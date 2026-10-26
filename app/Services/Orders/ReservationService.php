<?php

namespace App\Services\Orders;

use App\Events\OrderOversold;
use App\Events\StockChanged;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Product;
use App\Models\Reservation;
use App\Services\Orders\Exceptions\InsufficientAvailability;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Keeps each order line's reservation equal to its outstanding quantity
 * (ordered - dispatched - cancelled). The single invariant makes every change
 * (new order, edit, channel update, short-ship, cancel, dispatch) the same
 * operation: syncOrder().
 *
 * Increases are checked against site availability with the product rows
 * locked (SELECT ... FOR UPDATE, id order) AND with locking reads for the
 * figures themselves (the REPEATABLE READ snapshot would otherwise be stale),
 * so concurrent orders cannot both take the last unit. Lock order everywhere:
 * products, then stock balances. When $allowOversell is false a shortfall throws and
 * nothing is reserved (all-or-nothing). When true (Ordermentum, D-3) the
 * reservation is made anyway and the order is flagged oversold.
 */
class ReservationService
{
    public function __construct(private AvailabilityService $availability) {}

    public function syncOrder(Order $order, bool $allowOversell): void
    {
        DB::transaction(function () use ($order, $allowOversell) {
            $order->loadMissing('site');
            $lines = $order->lines()->with(['reservation', 'product'])->get();

            $productIds = $lines->pluck('product_id')->unique()->sort()->values()->all();
            Product::whereIn('id', $productIds)->orderBy('id')->lockForUpdate()->get(['id']);

            $deltas = [];
            foreach ($lines as $line) {
                $target = $order->status->isActive() ? $line->outstandingBase() : 0;
                $current = $line->reservation?->qty_base ?? 0;
                $deltas[$line->id] = ['line' => $line, 'target' => $target, 'delta' => $target - $current];
            }

            $increases = [];
            foreach ($deltas as $d) {
                if ($d['delta'] > 0) {
                    $increases[$d['line']->product_id][] = $d;
                }
            }

            $shortfalls = [];
            $oversoldProducts = [];
            foreach ($increases as $productId => $items) {
                /** @var OrderLine $first */
                $first = $items[0]['line'];
                $available = $this->availability->available($first->product, $order->site, current: true);
                $needed = array_sum(array_column($items, 'delta'));
                if ($needed > $available) {
                    $oversoldProducts[] = $first->product->sku;
                    foreach ($items as $item) {
                        $shortfalls[] = ['line_ref' => $item['line']->line_ref, 'sku' => $first->product->sku, 'requested' => $item['line']->outstandingBase(), 'available' => max(0, $available)];
                    }
                }
            }

            if ($shortfalls !== [] && ! $allowOversell) {
                throw new InsufficientAvailability($shortfalls);
            }

            $changed = [];
            foreach ($deltas as $d) {
                if ($d['delta'] === 0) {
                    continue;
                }
                Reservation::updateOrCreate(
                    ['order_line_id' => $d['line']->id],
                    ['product_id' => $d['line']->product_id, 'site_id' => $order->site_id, 'qty_base' => $d['target']],
                );
                $changed[] = $d['line']->product_id;
            }

            if ($oversoldProducts !== []) {
                $order->forceFill(['oversold' => true, 'oversold_at' => $order->oversold_at ?? now()])->save();
                Audit::record('order_oversold', $order, meta: ['skus' => $oversoldProducts, 'channel' => $order->channel->value]);
                OrderOversold::dispatch($order->id, $oversoldProducts);
            }

            if ($changed !== []) {
                StockChanged::dispatch(array_values(array_unique($changed)));
            }
        });
    }
}
