<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Product;
use App\Models\Reservation;
use App\Services\Stock\StockQuery;
use App\Support\Audit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the oversold flags right: the order's, and each line's short quantity
 * (order_lines.oversold_base). The order flag is set when an order reserves beyond
 * what's available (ReservationService); after that, everything here follows stock.
 * A line comes off by itself once stock covers it, with an audit entry, and the order
 * flag comes off when no line is short any more. The order flag is never set here.
 *
 * A product short at a site is short for the lines that took it last: lines of orders
 * already flagged oversold first (newest order first), then the rest, newest first;
 * within an order, its last line first. So stock arriving goes to the oldest orders,
 * and an order flagged short keeps its flag until the whole shortfall is covered or
 * falls on other orders.
 */
class OversoldCheck
{
    public function __construct(private StockQuery $stock) {}

    /**
     * @param  list<int>|null  $productIds  only orders holding these products; null for all
     * @return list<array{order: Order, cleared: bool, short: list<string>}> each flagged order looked at
     */
    public function recheck(?array $productIds = null, bool $dryRun = false, string $trigger = 'stock_changed'): array
    {
        $active = array_map(fn (OrderStatus $s) => $s->value, array_filter(OrderStatus::cases(), fn (OrderStatus $s) => $s->isActive()));
        $orders = Order::query()
            // Flagged open orders, and any order still carrying a line flag (one since closed, say).
            ->where(fn ($q) => $q->where(fn ($w) => $w->where('oversold', true)->whereIn('status', $active))
                ->orWhereHas('lines', fn ($l) => $l->where('oversold_base', '>', 0)))
            ->when($productIds !== null, fn ($q) => $q->whereHas('lines', fn ($l) => $l->whereIn('product_id', $productIds)))
            ->orderBy('id')
            ->get();

        $results = [];
        foreach ($orders as $order) {
            $results[] = $dryRun ? ['order' => $order, 'cleared' => false, 'short' => $this->shortSkus($order)] : $this->refresh($order, $trigger);
        }

        return $results;
    }

    /**
     * Brings one order's line flags up to date, and clears its order flag once no line is short.
     *
     * @return array{order: Order, cleared: bool, short: list<string>}
     */
    public function refresh(Order $order, string $trigger): array
    {
        return DB::transaction(function () use ($order, $trigger) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $active = $order->status->isActive();
            $short = $active ? $this->lineShortfalls($order) : [];

            foreach ($order->lines()->with('product:id,sku')->get() as $line) {
                $now = $short[$line->id] ?? 0;
                if ($now === $line->oversold_base) {
                    continue;
                }
                $was = $line->oversold_base;
                $line->forceFill(['oversold_base' => $now])->save();
                if ($was > 0 && $now === 0) {
                    Audit::record('order_line_oversold_cleared', $order, old: ['oversold_base' => $was], new: ['oversold_base' => 0], meta: [
                        'line_id' => $line->id, 'line_ref' => $line->line_ref, 'sku' => $line->product->sku,
                        'reason' => $active ? 'Stock now covers the line' : "Order is {$order->status->label()}", 'trigger' => $trigger,
                    ]);
                }
            }

            $skus = $this->skus(array_keys(array_filter($short)));
            $cleared = $order->oversold && $active && $skus === [];
            if ($cleared) {
                $order->forceFill(['oversold' => false])->save();
                Audit::record('order_oversold_cleared', $order, old: ['oversold' => true], new: ['oversold' => false], meta: [
                    'reason' => 'Stock now covers the order',
                    'trigger' => $trigger,
                    'oversold_at' => $order->oversold_at?->toIso8601String(),
                ]);
            }

            return ['order' => $order, 'cleared' => $cleared, 'short' => $skus];
        });
    }

    /** SKUs of the order still short at its site. @return list<string> */
    public function shortSkus(Order $order): array
    {
        return $this->skus(array_keys(array_filter($this->lineShortfalls($order))));
    }

    /** Base units short per line of this order. @return array<int, int> line id => short */
    public function lineShortfalls(Order $order): array
    {
        $productIds = Reservation::whereIn('order_line_id', $order->lines()->pluck('id'))->where('qty_base', '>', 0)->distinct()->pluck('product_id');
        $lineIds = $order->lines()->pluck('id')->all();
        $out = [];
        foreach ($productIds as $productId) {
            $out += array_intersect_key($this->shortLines((int) $productId, $order->site_id), array_flip($lineIds));
        }

        return $out;
    }

    /** Where a product's shortfall at a site falls. @return array<int, int> line id => base units short */
    private function shortLines(int $productId, int $siteId): array
    {
        $product = Product::findOrFail($productId);
        $site = Location::findOrFail($siteId);
        /** @var Collection<int, object{line_id: int, qty: int}> $holds */
        $holds = Reservation::query()
            ->join('order_lines', 'order_lines.id', '=', 'reservations.order_line_id')
            ->join('orders', 'orders.id', '=', 'order_lines.order_id')
            ->where('reservations.product_id', $productId)->where('reservations.site_id', $siteId)
            ->where('reservations.qty_base', '>', 0)
            ->orderByDesc('orders.oversold')->orderByDesc('orders.id')->orderByDesc('order_lines.id')
            ->toBase()->get(['order_lines.id as line_id', 'reservations.qty_base as qty']);

        $shortfall = (int) $holds->sum('qty') - $this->stock->sellableOnHand($product, $site, current: true);
        $short = [];
        foreach ($holds as $hold) {
            if ($shortfall <= 0) {
                break;
            }
            $take = min($shortfall, (int) $hold->qty);
            $short[(int) $hold->line_id] = $take;
            $shortfall -= $take;
        }

        return $short;
    }

    /** @param  list<int>  $lineIds  @return list<string> */
    private function skus(array $lineIds): array
    {
        return OrderLine::whereIn('order_lines.id', $lineIds)->join('products', 'products.id', '=', 'order_lines.product_id')
            ->distinct()->orderBy('products.sku')->pluck('products.sku')->all();
    }
}
