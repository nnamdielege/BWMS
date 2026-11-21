<?php

namespace App\Services\Finance;

use App\Enums\OrderChannel;
use App\Models\Order;
use App\Models\PickList;
use App\Models\Product;
use App\Services\Reports\PackPricing;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * GST and cost of goods from WMS sales, for Income allocation. All figures in cents.
 *
 * A sale is what was dispatched in the period (Brisbane dates), on every channel: each
 * dispatched pick line, at the order line's price (ex GST; a line with no price uses the
 * default/Ordermentum price and counts as estimated), plus non-stock lines (services, such as
 * a delivery fee), which go out with the order's first dispatched pick list. GST is 10% of each
 * line, rounded to the cent, on products not marked GST-free (as they are flagged today).
 * COGS is the cost on the dispatch movement, else today's average cost.
 *
 * GST credits: 10% (each PO line's own rate) on stock received in the period, at the PO cost.
 */
class SalesGst
{
    /** @var array<string, ?float> */
    private array $priceCache = [];

    /**
     * @return array{taxable_ex: int, gst: int, gst_free_ex: int, total_ex: int, cogs: int, lines: int, estimated: int,
     *               channels: list<array{channel: string, label: string, taxable_ex: int, gst: int, gst_free_ex: int}>}
     */
    public function period(string $from, string $to): array
    {
        [$start, $end] = self::window($from, $to);
        $rows = $this->stockLines($start, $end)->concat($this->serviceLines($start, $end));

        $channels = [];
        foreach (OrderChannel::cases() as $c) {
            $channels[$c->value] = ['channel' => $c->value, 'label' => $c->label(), 'taxable_ex' => 0, 'gst' => 0, 'gst_free_ex' => 0];
        }
        $totals = ['taxable_ex' => 0, 'gst' => 0, 'gst_free_ex' => 0, 'cogs' => 0, 'lines' => 0, 'estimated' => 0];
        foreach ($rows as $r) {
            $key = $r->gst_free ? 'gst_free_ex' : 'taxable_ex';
            $channels[$r->channel] ??= ['channel' => $r->channel, 'label' => ucfirst($r->channel), 'taxable_ex' => 0, 'gst' => 0, 'gst_free_ex' => 0];
            $channels[$r->channel][$key] += $r->sales;
            $channels[$r->channel]['gst'] += $r->gst;
            $totals[$key] += $r->sales;
            $totals['gst'] += $r->gst;
            $totals['cogs'] += $r->cost;
            $totals['lines']++;
            $totals['estimated'] += $r->estimated ? 1 : 0;
        }

        return $totals + [
            'total_ex' => $totals['taxable_ex'] + $totals['gst_free_ex'],
            'channels' => array_values($channels),
        ];
    }

    /** GST credits on supplier purchases received in the period, in cents. */
    public function credits(string $from, string $to): int
    {
        [$start, $end] = self::window($from, $to);

        return (int) DB::table('receipt_lines as rl')
            ->join('receipts as r', 'r.id', '=', 'rl.receipt_id')
            ->join('purchase_order_lines as pol', 'pol.id', '=', 'rl.purchase_order_line_id')
            ->whereBetween('r.received_at', [$start, $end])
            ->where('rl.qty_accepted_base', '>', 0)
            ->where('pol.tax_rate', '>', 0)
            ->get(['rl.qty_accepted_base', 'pol.unit_cost', 'pol.base_qty_per_unit', 'pol.tax_rate'])
            ->sum(fn ($l) => (int) round($l->qty_accepted_base / max(1, (int) $l->base_qty_per_unit) * (float) $l->unit_cost * (float) $l->tax_rate, 0, PHP_ROUND_HALF_UP));
    }

    /**
     * The inc-GST taxable share of one order's value (what's not cancelled), for apportioning a
     * receipt that pays it. Null if the order has no value.
     */
    public function orderTaxableShare(Order $order): ?float
    {
        $taxable = 0;
        $free = 0;
        foreach ($order->lines()->with('product.units', 'product.channelListings')->get() as $line) {
            $qtyBase = max(0, $line->qty_base - $line->qty_cancelled_base);
            $price = $line->unit_price !== null ? (float) $line->unit_price : PackPricing::sellFor($line->product, $line->base_qty_per_unit);
            $value = (int) round($qtyBase / max(1, $line->base_qty_per_unit) * ($price ?? 0) * 100);
            $line->product->gst_free ? $free += $value : $taxable += $value;
        }

        return AllocationMath::taxableShare($taxable, $free, Product::GST_RATE);
    }

    /** @return array{0: Carbon, 1: Carbon} UTC bounds of Brisbane dates $from to $to inclusive */
    public static function window(string $from, string $to): array
    {
        $tz = config('bwms.business_timezone');

        return [Carbon::parse($from, $tz)->startOfDay()->utc(), Carbon::parse($to, $tz)->endOfDay()->utc()];
    }

    private function stockLines(Carbon $start, Carbon $end): Collection
    {
        return DB::table('pick_list_lines as pll')
            ->join('pick_lists as pl', 'pl.id', '=', 'pll.pick_list_id')
            ->join('order_lines as ol', 'ol.id', '=', 'pll.order_line_id')
            ->join('orders as o', 'o.id', '=', 'ol.order_id')
            ->join('products as p', 'p.id', '=', 'pll.product_id')
            ->leftJoin('stock_movements as sm', 'sm.idempotency_key', '=', DB::raw("CONCAT('disp:', pll.id)"))
            ->where('pl.status', PickList::STATUS_DISPATCHED)
            ->whereBetween('pl.dispatched_at', [$start, $end])
            ->where('pll.qty_picked_base', '>', 0)
            ->get(['o.channel', 'p.id as product_id', 'p.gst_free', 'p.avg_cost', 'pll.qty_picked_base as units', 'ol.base_qty_per_unit', 'ol.unit_price', 'sm.unit_cost'])
            ->map(fn ($r) => $this->line($r));
    }

    /** Services: no pick line, dispatched with the order's first dispatched pick list. */
    private function serviceLines(Carbon $start, Carbon $end): Collection
    {
        $firstDispatch = DB::table('pick_lists as pl2')
            ->join('pick_list_lines as pll2', 'pll2.pick_list_id', '=', 'pl2.id')
            ->join('order_lines as ol2', 'ol2.id', '=', 'pll2.order_line_id')
            ->whereColumn('ol2.order_id', 'ol.order_id')
            ->where('pl2.status', PickList::STATUS_DISPATCHED)
            ->selectRaw('MIN(pl2.dispatched_at)');

        return DB::table('order_lines as ol')
            ->join('orders as o', 'o.id', '=', 'ol.order_id')
            ->join('products as p', 'p.id', '=', 'ol.product_id')
            ->where('p.is_stock_tracked', false)
            ->where('ol.qty_dispatched_base', '>', 0)
            ->whereRaw('('.$firstDispatch->toSql().') BETWEEN ? AND ?', [...$firstDispatch->getBindings(), $start, $end])
            ->get(['o.channel', 'p.id as product_id', 'p.gst_free', 'p.avg_cost', 'ol.qty_dispatched_base as units', 'ol.base_qty_per_unit', 'ol.unit_price', DB::raw('NULL as unit_cost')])
            ->map(fn ($r) => $this->line($r));
    }

    private function line(object $r): object
    {
        $price = $r->unit_price !== null ? (float) $r->unit_price : $this->price((int) $r->product_id, (int) $r->base_qty_per_unit);
        $sales = (int) round((int) $r->units / max(1, (int) $r->base_qty_per_unit) * ($price ?? 0) * 100);

        return (object) [
            'channel' => $r->channel,
            'gst_free' => (bool) $r->gst_free,
            'sales' => $sales,
            'gst' => AllocationMath::gstOn($sales, (bool) $r->gst_free, Product::GST_RATE),
            'cost' => (int) round((int) $r->units * (float) ($r->unit_cost ?? $r->avg_cost) * 100),
            'estimated' => $r->unit_price === null,
        ];
    }

    private function price(int $productId, int $packQty): ?float
    {
        $key = "{$productId}:{$packQty}";
        if (! array_key_exists($key, $this->priceCache)) {
            $p = Product::withTrashed()->with(['units', 'channelListings' => fn ($q) => $q->where('channel', 'ordermentum')])->find($productId);
            $this->priceCache[$key] = $p ? PackPricing::sellFor($p, $packQty) : null;
        }

        return $this->priceCache[$key];
    }
}
