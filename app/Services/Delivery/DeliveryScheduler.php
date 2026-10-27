<?php

namespace App\Services\Delivery;

use App\Models\Customer;
use App\Models\DeliveryArea;
use App\Models\DeliveryAreaPlace;
use App\Models\DeliveryRun;
use App\Models\Order;
use App\Models\PickListLine;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\QueryException;

/**
 * Delivery runs (decided 2026-10-27). Delivery is by area on fixed weekdays;
 * some areas also get next-day delivery on any weekday. Orders must be in by
 * the cutoff (13:00) on the business day before delivery. A run = date + area.
 *
 * An order's run comes from its delivery address (else its customer's), using
 * the requested date if one is given, otherwise the next eligible run after
 * the cutoff. Business days are Mon-Fri; public holidays are not modelled yet.
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

    /**
     * Assign (or re-assign) an order's run. Manual assignments and orders already
     * being picked or dispatched keep their run.
     */
    public function assign(Order $order): void
    {
        if ($order->run_assignment === 'manual' || $this->beingPicked($order)) {
            return;
        }
        $customer = $order->customer_id ? Customer::find($order->customer_id) : null;
        $postcode = $order->delivery_postcode ?: $customer?->delivery_postcode;
        $suburb = $order->delivery_postcode ? $order->delivery_suburb : $customer?->delivery_suburb;
        $area = ($customer?->delivery_area_id ? DeliveryArea::find($customer->delivery_area_id) : null) ?? $this->areaFor($postcode, $suburb);

        if (! $area) {
            $order->forceFill(['delivery_run_id' => null, 'run_assignment' => 'unassigned'])->save();

            return;
        }

        $date = $order->requested_delivery_date
            ? CarbonImmutable::parse($order->requested_delivery_date->toDateString(), config('bwms.business_timezone'))
            : $this->nextRunDate($area, $order->created_at ?? now());

        $order->forceFill(['delivery_run_id' => $this->run($order->site_id, $area, $date)->id, 'run_assignment' => 'auto'])->save();
    }

    /** Staff override from the order page: sticks across later channel updates. */
    public function assignManually(Order $order, DeliveryArea $area, DateTimeInterface $date): void
    {
        $order->forceFill(['delivery_run_id' => $this->run($order->site_id, $area, CarbonImmutable::instance($date))->id, 'run_assignment' => 'manual'])->save();
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

    private function beingPicked(Order $order): bool
    {
        return $order->exists && PickListLine::whereIn('order_line_id', $order->lines()->pluck('id'))->exists();
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
