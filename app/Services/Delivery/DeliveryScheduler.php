<?php

namespace App\Services\Delivery;

use App\Models\Customer;
use App\Models\DeliveryArea;
use App\Models\DeliveryAreaPlace;
use App\Models\DeliveryRun;
use App\Models\Order;
use App\Models\PickList;
use App\Models\PickListLine;
use App\Services\Orders\ConsolidationService;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\QueryException;

/**
 * Delivery runs (decided 2026-10-27). Delivery is by area on fixed weekdays;
 * some areas also get next-day delivery on any weekday. Orders must be in by
 * the cutoff (13:00) on the business day before delivery. A run = date + area.
 *
 * Every order has one delivery_date: the date its source gave, otherwise the next
 * eligible run after the cutoff, flagged for review. The source's date is never
 * moved to suit the area: a date the area doesn't run is kept and flagged. The
 * area comes from the customer's override, then the delivery address, then the
 * customer's address. A run = delivery_date + area.
 *
 * Only a change to the source's date moves an order, so re-sending an unchanged
 * order leaves a date a manager set. An order on a pick list, or on a run set by
 * hand, never moves on its own: it is flagged with both dates instead. Nor does
 * one a manager consolidated: a different source date flags all three dates.
 *
 * Business days are Mon-Fri; public holidays are not modelled yet.
 */
class DeliveryScheduler
{
    public function areaFor(?string $postcode, ?string $suburb): ?DeliveryArea
    {
        $postcode = trim((string) $postcode);
        if (! preg_match('/^\d{4}$/', $postcode)) {
            return null;
        }
        $suburb = mb_strtolower(trim((string) $suburb));
        $places = DeliveryAreaPlace::where('postcode', $postcode)->whereHas('area', fn ($q) => $q->where('is_active', true))->with('area')->get();

        // A suburb-specific entry beats a whole-postcode entry (postcodes split across areas).
        return $places->first(fn ($p) => $p->suburb !== '' && mb_strtolower($p->suburb) === $suburb)?->area
            ?? $places->first(fn ($p) => $p->suburb === '')?->area;
    }

    /** The cutoff for a delivery date: 13:00 (business time) on the business day before. */
    public function cutoffFor(CarbonImmutable $deliveryDate): CarbonImmutable
    {
        [$h, $m] = array_map('intval', explode(':', (string) config('bwms.delivery.cutoff_time')));

        return $this->previousBusinessDay($deliveryDate)->setTime($h, $m);
    }

    /** First date after $placedAt this area can deliver on, honouring the cutoff. */
    public function nextRunDate(DeliveryArea $area, DateTimeInterface $placedAt): CarbonImmutable
    {
        $tz = config('bwms.business_timezone');
        $placed = CarbonImmutable::instance($placedAt)->setTimezone($tz);
        $date = $placed->startOfDay()->addDay();

        for ($i = 0; $i < 21; $i++, $date = $date->addDay()) {
            if (! $this->isBusinessDay($date)) {
                continue;
            }
            $scheduled = $area->deliversOn($date->dayOfWeekIso) || $area->next_day_eligible;
            if ($scheduled && $placed->lt($this->cutoffFor($date))) {
                return $date;
            }
        }

        return $date; // unreachable for an area with at least one weekday
    }

    /** Customer override, then the order's delivery address, then the customer's. */
    public function areaForOrder(Order $order): ?DeliveryArea
    {
        $customer = $order->customer_id ? Customer::find($order->customer_id) : null;
        $postcode = $order->delivery_postcode ?: $customer?->delivery_postcode;
        $suburb = $order->delivery_postcode ? $order->delivery_suburb : $customer?->delivery_suburb;

        return ($customer?->delivery_area_id ? DeliveryArea::find($customer->delivery_area_id) : null) ?? $this->areaFor($postcode, $suburb);
    }

    /** The form's default: the next delivery day for this customer's area, from now. */
    public function nextDateForCustomer(Customer $customer): ?string
    {
        $area = $this->areaForOrder((new Order)->forceFill(['customer_id' => $customer->id]));

        return $area ? $this->nextRunDate($area, now())->toDateString() : null;
    }

    /** Whether the area has a run on that date: its weekdays, or any business day if it is next-day. */
    public function runsOn(DeliveryArea $area, CarbonImmutable $date): bool
    {
        return $area->deliversOn($date->dayOfWeekIso) || ($area->next_day_eligible && $this->isBusinessDay($date));
    }

    /** The date an order would get from this source date, written nowhere. */
    public function dateFor(Order $order, ?string $sourceDate): ?string
    {
        if ($sourceDate) {
            return $sourceDate;
        }
        $area = $this->areaForOrder($order);

        return $area ? $this->nextRunDate($area, $order->created_at ?? now())->toDateString() : null;
    }

    /**
     * Set an order's date and run, or flag why it stays.
     *
     * @param  bool  $dateChanged  the source sent a different date from the one it sent last
     */
    public function assign(Order $order, bool $dateChanged = false): void
    {
        $source = $order->requested_delivery_date?->toDateString();
        $current = $order->delivery_date?->toDateString();

        if ($order->delivery_date_reason === ConsolidationService::REASON && $dateChanged) {
            $this->flagAfterConsolidation($order, $source, $current);

            return;
        }

        if ($order->run_assignment === 'manual' || $this->onPickList($order)) {
            if ($dateChanged && $source && $source !== $current) {
                $why = $this->onPickList($order) ? 'it is already being picked' : 'its run was set by hand';
                $order->flag('date_changed', "{$order->channel->label()} changed the delivery date from {$current} to {$source}. It stays on {$current} because {$why}.");
                $order->save();
            }

            return;
        }

        $area = $this->areaForOrder($order);
        if ($current && ! $dateChanged && $order->delivery_date_origin !== 'fallback') {
            $this->place($order, $area, $current, $order->delivery_date_origin ?? 'source', $order->delivery_date_reason);

            return;
        }
        $this->place($order, $area, $this->dateFor($order, $source), $source ? 'source' : 'fallback');
    }

    /** A manager's date: same rules, and it stays until the source sends a different one. */
    public function moveTo(Order $order, string $date): void
    {
        $area = $order->run_assignment === 'manual' ? $order->deliveryRun?->area : $this->areaForOrder($order);
        $this->place($order, $area, $date, 'manager');
    }

    /** Staff override from the order page: sticks across later channel updates. */
    public function assignManually(Order $order, DeliveryArea $area, DateTimeInterface $date): void
    {
        $date = CarbonImmutable::instance($date)->toDateString();
        $order->forceFill(['run_assignment' => 'manual']);
        $this->place($order, $area, $date, 'manager', $date === $order->delivery_date?->toDateString() ? $order->delivery_date_reason : null);
    }

    /** A date chosen on purpose, on the order's own area: no weekday flag. The first original date stays through a second move. */
    public function consolidate(Order $order, string $date): void
    {
        if ($order->delivery_date_reason !== ConsolidationService::REASON) {
            $order->forceFill(['original_delivery_date' => $order->delivery_date, 'original_delivery_date_origin' => $order->delivery_date_origin]);
        }
        $this->place($order, $order->deliveryRun->area, $date, 'manager', ConsolidationService::REASON);
    }

    /** Back where it came from. If the source has since moved on, that stays flagged for a person. */
    public function undoConsolidation(Order $order): void
    {
        $original = $order->original_delivery_date->toDateString();
        $this->place($order, $order->deliveryRun->area, $original, $order->original_delivery_date_origin ?? 'source');

        $source = $order->requested_delivery_date?->toDateString();
        if ($source && $source !== $original) {
            $order->flag('date_changed', "{$order->channel->label()} now says {$source}. The order went back to {$original}.");
            $order->save();
        }
    }

    /** A date differing from both the original and the consolidated one is for a person. Nothing moves. */
    private function flagAfterConsolidation(Order $order, ?string $source, ?string $current): void
    {
        $original = $order->original_delivery_date?->toDateString();
        if ($source && ! in_array($source, [$current, $original], true)) {
            $order->flag('date_changed', "{$order->channel->label()} changed the delivery date to {$source}. Original {$original}, consolidated to {$current}. It stays on {$current}.");
        } else {
            $order->unflag('date_changed');
        }
        $order->save();
    }

    private function place(Order $order, ?DeliveryArea $area, ?string $date, string $origin, ?string $reason = null): void
    {
        $day = $date ? CarbonImmutable::parse($date, config('bwms.business_timezone')) : null;
        $manual = $order->run_assignment === 'manual';

        if ($reason !== ConsolidationService::REASON) {
            $order->forceFill(['original_delivery_date' => null, 'original_delivery_date_origin' => null]);
        }
        $order->forceFill([
            'delivery_date' => $date,
            'delivery_date_origin' => $origin,
            'delivery_date_reason' => $reason,
            'delivery_run_id' => $area && $day ? $this->run($order->site_id, $area, $day)->id : null,
            'run_assignment' => $manual ? 'manual' : ($area && $day ? 'auto' : 'unassigned'),
        ]);

        $origin === 'fallback' ? $order->flag('no_date', "No delivery date from {$order->channel->label()}") : $order->unflag('no_date');
        $area && $day && $reason !== ConsolidationService::REASON && ! $this->runsOn($area, $day) ? $order->flag('not_delivery_day', "Not a delivery day for {$area->name}") : $order->unflag('not_delivery_day');
        $order->unflag('date_changed');
        $order->save();
    }

    public function run(int $siteId, DeliveryArea $area, CarbonImmutable $date): DeliveryRun
    {
        $key = ['site_id' => $siteId, 'delivery_area_id' => $area->id, 'run_date' => $date->toDateString()];
        try {
            return DeliveryRun::firstOrCreate($key, ['status' => DeliveryRun::STATUS_OPEN]);
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                return DeliveryRun::where($key)->firstOrFail(); // created concurrently
            }
            throw $e;
        }
    }

    /** On a pick list that wasn't cancelled: already picking, packed or dispatched. */
    public function onPickList(Order $order): bool
    {
        return $order->exists && PickListLine::whereIn('order_line_id', $order->lines()->pluck('id'))
            ->whereHas('pickList', fn ($q) => $q->where('status', '!=', PickList::STATUS_CANCELLED))->exists();
    }

    private function isBusinessDay(CarbonImmutable $date): bool
    {
        return $date->dayOfWeekIso <= 5;
    }

    private function previousBusinessDay(CarbonImmutable $date): CarbonImmutable
    {
        $d = $date->subDay();
        while (! $this->isBusinessDay($d)) {
            $d = $d->subDay();
        }

        return $d;
    }
}
