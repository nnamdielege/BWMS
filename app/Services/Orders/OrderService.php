<?php

namespace App\Services\Orders;

use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Models\Location;
use App\Models\Order;
use App\Models\PickListLine;
use App\Models\Product;
use App\Models\User;
use App\Services\Stock\Exceptions\InvalidMovement;
use App\Support\Audit;
use App\Support\NumberSequence;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class OrderService
{
    public function __construct(private ReservationService $reservations) {}

    /**
     * Manual order. Blocked when stock is short unless $allowBackorder
     * (then reserved anyway and flagged oversold for follow-up).
     *
     * @param  array{customer_id:int, site_id:int, requested_delivery_date?:?string, notes?:?string, short_ship_policy?:string,
     *               lines: list<array{product_id:int, product_unit_id:int, qty:int, unit_price?:?string}>}  $data
     */
    public function createManual(User $actor, array $data, bool $allowBackorder = false): Order
    {
        return DB::transaction(function () use ($actor, $data, $allowBackorder) {
            $order = $this->newOrder(OrderChannel::Manual, null, $data['site_id'], $actor);
            $order->fill(collect($data)->only(['customer_id', 'requested_delivery_date', 'notes', 'short_ship_policy'])->all())->save();
            $this->writeLines($order, array_map(fn ($l, $i) => $l + ['line_ref' => (string) ($i + 1)], $data['lines'], array_keys($data['lines'])));

            $this->reservations->syncOrder($order, $allowBackorder);

            return $order->refresh();
        });
    }

    /**
     * Idempotent full-state upsert from a channel. A version <= the stored one
     * is ignored (replay or out-of-order). Portal: all-or-nothing, throws
     * InsufficientAvailability. Ordermentum: always accepted (D-3).
     */
    public function upsertFromChannel(ChannelOrderData $data): Order
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(fn () => $this->applyChannel($data));
            } catch (QueryException $e) {
                // 1062: two first deliveries raced on (channel, external_ref); retry finds the winner.
                // 1213/1205: deadlock or lock wait; safe to retry the whole upsert (outermost only).
                $retryable = in_array($e->errorInfo[1] ?? null, [1062, 1213, 1205], true);
                if ($retryable && $attempt < 5 && DB::transactionLevel() === 0) {
                    usleep(random_int(5_000, 25_000) * $attempt);

                    continue;
                }
                throw $e;
            }
        }
    }

    private function applyChannel(ChannelOrderData $data): Order
    {
        // Plain read for the id, then lock by primary key only if it exists. A locking
        // read on a missing (channel, ref) takes a gap lock, and concurrent first
        // deliveries deadlock on each other's inserts. A racing first insert is
        // caught by the unique index (1062) and the whole upsert is retried.
        $existingId = Order::where('channel', $data->channel)->where('external_ref', $data->externalRef)->value('id');
        $order = $existingId ? Order::whereKey($existingId)->lockForUpdate()->first() : null;

        if ($order && $data->version <= $order->external_version) {
            return $order; // already applied
        }

        if (! $order) {
            if ($data->status === 'cancelled') {
                throw new InvalidMovement("Unknown {$data->channel->value} order {$data->externalRef} cannot be cancelled.");
            }
            $order = $this->newOrder($data->channel, $data->externalRef, $data->siteId, null);
        }

        $order->fill(['customer_id' => $data->customerId, 'requested_delivery_date' => $data->requestedDeliveryDate, 'notes' => $data->notes]);
        $order->forceFill(['external_version' => $data->version])->save();

        if ($data->status === 'cancelled') {
            return $this->cancel($order, null, "Cancelled in {$data->channel->label()}");
        }

        $this->assert($order->status->isActive(), "{$order->number} is {$order->status->label()} and cannot be changed.");
        $onOpenPickList = PickListLine::whereIn('order_line_id', $order->lines()->pluck('id'))
            ->whereHas('pickList', fn ($q) => $q->where('status', 'open'))->exists();

        $this->writeLines($order, $data->lines);
        if ($onOpenPickList) {
            $order->forceFill(['needs_review' => true])->save();
        }

        $this->reservations->syncOrder($order->refresh(), $data->channel->alwaysAccept());

        return $order->refresh();
    }

    /** Cancels the undispatched remainder and releases its reservation. Removes unposted pick lines. */
    public function cancel(Order $order, ?User $actor, ?string $reason): Order
    {
        return DB::transaction(function () use ($order, $actor, $reason) {
            $order = Order::lockForUpdate()->findOrFail($order->id);
            $this->assert($order->status->isActive(), "{$order->number} is {$order->status->label()}.");

            // Nothing on a pick list has moved in the ledger yet, so dropping those lines is safe.
            PickListLine::whereIn('order_line_id', $order->lines()->pluck('id'))
                ->whereHas('pickList', fn ($q) => $q->where('status', 'open'))->delete();

            foreach ($order->lines as $line) {
                $line->forceFill(['qty_cancelled_base' => $line->qty_cancelled_base + $line->outstandingBase()])->save();
            }
            $anyDispatched = $order->lines->sum('qty_dispatched_base') > 0;
            $order->forceFill([
                'status' => $anyDispatched ? OrderStatus::Dispatched : OrderStatus::Cancelled,
                'cancelled_at' => now(), 'cancelled_by' => $actor?->id, 'cancel_reason' => $reason,
            ])->save();

            $this->reservations->syncOrder($order, true);
            Audit::record('order_cancelled', $order, meta: ['reason' => $reason]);

            return $order;
        });
    }

    private function newOrder(OrderChannel $channel, ?string $externalRef, int $siteId, ?User $actor): Order
    {
        $site = Location::findOrFail($siteId);
        $this->assert($site->type === Location::TYPE_SITE && $site->is_active, 'Orders must be fulfilled from an active site.');

        $order = new Order(['site_id' => $site->id]);
        $order->forceFill([
            'number' => NumberSequence::next('order', 'SO'),
            'channel' => $channel,
            'external_ref' => $externalRef,
            'status' => OrderStatus::Open,
            'created_by' => $actor?->id,
        ])->save();

        return $order;
    }

    /**
     * Upsert lines by line_ref. Lines missing from the new state keep what was
     * already dispatched and cancel the rest. A quantity reduced below what was
     * dispatched flags the order for review.
     *
     * @param  list<array{line_ref: string, product_id: int, product_unit_id: int, qty: int, unit_price?: ?string}>  $lines
     */
    private function writeLines(Order $order, array $lines): void
    {
        $existing = $order->lines()->get()->keyBy('line_ref');
        $seen = [];

        foreach (array_values($lines) as $i => $row) {
            $product = Product::findOrFail($row['product_id']);
            $unit = $product->units()->whereKey($row['product_unit_id'])->firstOrFail();
            $this->assert($unit->sellable, "{$product->sku}: pack \"{$unit->name}\" is not sellable.");
            $qtyBase = $unit->toBase((int) $row['qty']);
            $ref = (string) $row['line_ref'];
            $seen[] = $ref;

            $line = $existing[$ref] ?? $order->lines()->make(['line_ref' => $ref]);
            if ($line->exists && $qtyBase < $line->qty_dispatched_base) {
                $order->forceFill(['needs_review' => true])->save();
            }
            $line->forceFill([
                'product_id' => $product->id,
                'product_unit_id' => $unit->id,
                'qty' => (int) $row['qty'],
                'base_qty_per_unit' => $unit->base_qty,
                'qty_base' => max($qtyBase, $line->qty_dispatched_base ?? 0),
                'qty_cancelled_base' => 0,
                'unit_price' => $row['unit_price'] ?? null,
                'sort_order' => $i,
            ])->save();
        }

        foreach ($existing as $ref => $line) {
            if (! in_array((string) $ref, $seen, true)) {
                $line->forceFill(['qty_cancelled_base' => $line->qty_base - $line->qty_dispatched_base])->save();
            }
        }
    }

    private function assert(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new InvalidMovement($message);
        }
    }
}
