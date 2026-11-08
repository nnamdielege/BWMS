<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Models\Location;
use App\Models\Order;
use App\Models\Product;
use App\Models\Reservation;
use App\Services\Stock\StockQuery;
use App\Support\Audit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Re-checks the oversold flag on open orders after stock changes. The flag is
 * set when an order reserves beyond what's available; it comes off by itself,
 * with an audit entry, once the site's sellable stock covers the order again.
 * It is never set here.
 *
 * A product short at a site is short for the orders that took it last: those
 * already flagged oversold first (newest first), then the rest, newest first.
 * So stock arriving goes to the oldest orders, and an order flagged short keeps
 * its flag until the whole shortfall is covered or falls on other orders.
 */
class OversoldCheck
{
    public function __construct(private StockQuery $stock) {}

    /**
     * @param  list<int>|null  $productIds  only orders holding these products; null for all
     * @return list<array{order: Order, cleared: bool, short: list<string>}> each flagged open order looked at
     */
    public function recheck(?array $productIds = null, bool $dryRun = false, string $trigger = 'stock_changed'): array
    {
        $orders = Order::where('oversold', true)
            ->whereIn('status', array_map(fn (OrderStatus $s) => $s->value, array_filter(OrderStatus::cases(), fn (OrderStatus $s) => $s->isActive())))
            ->when($productIds !== null, fn ($q) => $q->whereHas('lines', fn ($l) => $l->whereIn('product_id', $productIds)))
            ->orderBy('id')
            ->get();

        $results = [];
        foreach ($orders as $order) {
            $results[] = $dryRun ? ['order' => $order, 'cleared' => false, 'short' => $this->shortSkus($order)] : $this->clearIfCovered($order, $trigger);
        }

        return $results;
    }

    /** @return array{order: Order, cleared: bool, short: list<string>} */
    private function clearIfCovered(Order $order, string $trigger): array
    {
        return DB::transaction(function () use ($order, $trigger) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $short = $order->oversold && $order->status->isActive() ? $this->shortSkus($order) : [];
            $cleared = $order->oversold && $order->status->isActive() && $short === [];
            if ($cleared) {
                $order->forceFill(['oversold' => false])->save();
                Audit::record('order_oversold_cleared', $order, old: ['oversold' => true], new: ['oversold' => false], meta: [
                    'reason' => 'Stock now covers the order',
                    'trigger' => $trigger,
                    'oversold_at' => $order->oversold_at?->toIso8601String(),
                ]);
            }

            return ['order' => $order, 'cleared' => $cleared, 'short' => $short];
        });
    }

    /** SKUs of the order still short at its site. @return list<string> */
    public function shortSkus(Order $order): array
    {
        $reserved = Reservation::whereIn('order_line_id', $order->lines()->pluck('id'))->where('qty_base', '>', 0)
            ->get()->groupBy('product_id');
        $short = [];
        foreach ($reserved as $productId => $rows) {
            if (in_array($order->id, $this->shortOrders((int) $productId, $order->site_id), true)) {
                $short[] = Product::whereKey($productId)->value('sku');
            }
        }
        sort($short);

        return $short;
    }

    /** Orders the product's shortfall at the site falls on. @return list<int> */
    private function shortOrders(int $productId, int $siteId): array
    {
        $product = Product::findOrFail($productId);
        $site = Location::findOrFail($siteId);
        /** @var Collection<int, object{order_id: int, oversold: int, qty: int}> $holds */
        $holds = Reservation::query()
            ->join('order_lines', 'order_lines.id', '=', 'reservations.order_line_id')
            ->join('orders', 'orders.id', '=', 'order_lines.order_id')
            ->where('reservations.product_id', $productId)->where('reservations.site_id', $siteId)
            ->where('reservations.qty_base', '>', 0)
            ->groupBy('orders.id', 'orders.oversold')
            ->selectRaw('orders.id as order_id, orders.oversold, SUM(reservations.qty_base) as qty')
            ->orderByDesc('orders.oversold')->orderByDesc('orders.id')
            ->toBase()->get();

        $shortfall = (int) $holds->sum('qty') - $this->stock->sellableOnHand($product, $site, current: true);
        $short = [];
        foreach ($holds as $hold) {
            if ($shortfall <= 0) {
                break;
            }
            $short[] = (int) $hold->order_id;
            $shortfall -= (int) $hold->qty;
        }

        return $short;
    }
}
