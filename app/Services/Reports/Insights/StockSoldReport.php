<?php

namespace App\Services\Reports\Insights;

use App\Enums\OrderChannel;
use App\Models\PickList;
use App\Models\Product;
use App\Services\Reports\MarginMath;
use App\Services\Reports\PackPricing;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reports → Stock sold: stock dispatched in the dates, by SKU, range, customer, channel and week.
 *
 * A sale is a dispatched pick line: the quantity that left, on the day its pick list was
 * dispatched (Brisbane). Sales $ = packs × the order line's price (ex GST); a line with no
 * price uses the product's default/Ordermentum price and is marked estimated. Cost = the
 * cost on the dispatch movement, else today's average cost. Non-stock lines (services) have
 * no pick line and aren't in this report.
 *
 * Lapsed: a customer who bought a product on at least LAPSED_MIN_ORDERS orders in the
 * LAPSED_LOOKBACK_DAYS up to the end date, and hasn't for longer than twice their usual gap
 * (at least LAPSED_MIN_DAYS).
 */
class StockSoldReport
{
    public const GROUPS = ['sku', 'range', 'customer', 'channel'];

    public const LAPSED_LOOKBACK_DAYS = 182;

    public const LAPSED_MIN_ORDERS = 3;

    public const LAPSED_MIN_DAYS = 28;

    /** @var array<string, ?float> */
    private array $priceCache = [];

    public function build(InsightFilters $f): array
    {
        [$start, $end] = $f->window();
        $rows = $this->sales($f, $start, $end);

        $groups = [];
        foreach (self::GROUPS as $by) {
            $groups[$by] = $this->group($rows, $by);
        }
        $byGp = fn (array $g) => collect($g)->sortBy([['gp', 'desc'], ['label', 'asc']])->values();

        return [
            'totals' => self::sum($rows) + [
                'customers' => $rows->pluck('customer_id')->filter()->unique()->count(),
                'estimated' => $rows->where('estimated', true)->count(),
            ],
            'groups' => $groups,
            'trend' => $this->trend($rows, $f->from, $f->to),
            'top' => [
                'products' => $byGp($groups['sku'])->take(10)->values()->all(),
                'products_bottom' => $byGp($groups['sku'])->reverse()->take(10)->values()->all(),
                'customers' => $byGp($groups['customer'])->take(10)->values()->all(),
                'customers_bottom' => $byGp($groups['customer'])->reverse()->take(10)->values()->all(),
            ],
            'lapsed' => $this->lapsed($f),
        ];
    }

    /** One row per dispatched pick line in the window. */
    private function sales(InsightFilters $f, Carbon $start, Carbon $end): Collection
    {
        $tz = config('bwms.business_timezone');
        $q = DB::table('pick_list_lines as pll')
            ->join('pick_lists as pl', 'pl.id', '=', 'pll.pick_list_id')
            ->join('order_lines as ol', 'ol.id', '=', 'pll.order_line_id')
            ->join('orders as o', 'o.id', '=', 'ol.order_id')
            ->join('products as p', 'p.id', '=', 'pll.product_id')
            ->leftJoin('ranges as rg', 'rg.id', '=', 'p.range_id')
            ->leftJoin('customers as c', 'c.id', '=', 'o.customer_id')
            ->leftJoin('stock_movements as sm', 'sm.idempotency_key', '=', DB::raw("CONCAT('disp:', pll.id)"))
            ->where('pl.status', PickList::STATUS_DISPATCHED)
            ->whereBetween('pl.dispatched_at', [$start, $end])
            ->where('pll.qty_picked_base', '>', 0)
            ->when($f->siteId, fn ($q, $id) => $q->where('pl.site_id', $id));
        $f->apply($q, 'pll.product_id');

        return $q->orderBy('pl.dispatched_at')->orderBy('pll.id')->get([
            'pll.id', 'pl.dispatched_at', 'o.id as order_id', 'o.number as order', 'o.channel', 'o.customer_id', 'c.name as customer',
            'p.id as product_id', 'p.sku', 'p.name', 'p.base_unit', 'p.range_id', 'rg.name as range', 'p.avg_cost',
            'pll.qty_picked_base as units', 'ol.base_qty_per_unit', 'ol.unit_price', 'sm.unit_cost',
        ])->map(function ($r) use ($tz) {
            $price = $r->unit_price !== null ? (float) $r->unit_price : $this->price((int) $r->product_id, (int) $r->base_qty_per_unit);
            $r->units = (int) $r->units;
            $r->date = Carbon::parse($r->dispatched_at, 'UTC')->setTimezone($tz)->toDateString();
            $r->estimated = $r->unit_price === null;
            $r->sales = round($r->units / max(1, (int) $r->base_qty_per_unit) * ($price ?? 0), 2);
            $r->cost = round($r->units * (float) ($r->unit_cost ?? $r->avg_cost), 2);
            $r->gp = round($r->sales - $r->cost, 2);

            return $r;
        });
    }

    /** The default or Ordermentum price for a pack, for lines that carry no price. */
    private function price(int $productId, int $packQty): ?float
    {
        $key = "{$productId}:{$packQty}";
        if (! array_key_exists($key, $this->priceCache)) {
            $p = Product::withTrashed()->with(['units', 'channelListings' => fn ($q) => $q->where('channel', 'ordermentum')])->find($productId);
            $this->priceCache[$key] = $p ? PackPricing::sellFor($p, $packQty) : null;
        }

        return $this->priceCache[$key];
    }

    /** @return array{units: int, sales: float, cost: float, gp: float, gm_percent: ?float, orders: int} */
    private static function sum(Collection $rows): array
    {
        $sales = round($rows->sum('sales'), 2);
        $cost = round($rows->sum('cost'), 2);

        return [
            'units' => (int) $rows->sum('units'),
            'sales' => $sales,
            'cost' => $cost,
            'gp' => round($sales - $cost, 2),
            'gm_percent' => MarginMath::gmPercent($sales, $cost),
            'orders' => $rows->pluck('order_id')->unique()->count(),
        ];
    }

    /** @return list<array> biggest sales first */
    private function group(Collection $rows, string $by): array
    {
        [$key, $label] = match ($by) {
            'sku' => ['product_id', fn ($r) => trim("{$r->sku} {$r->name}")],
            'range' => ['range_id', fn ($r) => $r->range ?? 'No range'],
            'customer' => ['customer_id', fn ($r) => $r->customer ?? 'No customer'],
            'channel' => ['channel', fn ($r) => OrderChannel::tryFrom($r->channel)?->label() ?? $r->channel],
        };

        return $rows->groupBy(fn ($r) => (string) ($r->{$key} ?? ''))
            ->map(fn (Collection $g, string $k) => [
                'key' => $k,
                'label' => $label($g->first()),
                'product_id' => $by === 'sku' ? $g->first()->product_id : null,
                'base_unit' => $by === 'sku' ? $g->first()->base_unit : null,
            ] + self::sum($g))
            ->sortBy([['sales', 'desc'], ['label', 'asc']])->values()->all();
    }

    /** @return list<array{start: string, label: string, sales: float, gp: float, units: int, change_percent: ?float}> every week (Monday) in the dates */
    private function trend(Collection $rows, string $from, string $to): array
    {
        $week = fn (string $d) => Carbon::parse($d)->startOfWeek(Carbon::MONDAY)->toDateString();
        $byWeek = $rows->groupBy(fn ($r) => $week($r->date));
        $out = [];
        $prev = null;
        for ($d = Carbon::parse($week($from)); $d->lte(Carbon::parse($week($to))); $d->addWeek()) {
            $g = $byWeek[$d->toDateString()] ?? collect();
            $s = self::sum($g);
            $out[] = [
                'start' => $d->toDateString(),
                'label' => $d->format('j M'),
                'sales' => $s['sales'],
                'gp' => $s['gp'],
                'units' => $s['units'],
                'change_percent' => $prev !== null && $prev > 0 ? round(($s['sales'] - $prev) / $prev * 100, 1) : null,
            ];
            $prev = $s['sales'];
        }

        return $out;
    }

    /** @return list<array> customer + product pairs that have stopped, the biggest regular spend first */
    private function lapsed(InsightFilters $f): array
    {
        $tz = config('bwms.business_timezone');
        $end = Carbon::parse($f->to, $tz)->endOfDay()->utc();
        $start = Carbon::parse($f->to, $tz)->subDays(self::LAPSED_LOOKBACK_DAYS)->startOfDay()->utc();
        $rows = $this->sales($f, $start, $end)->whereNotNull('customer_id');
        $lastByCustomer = $rows->groupBy('customer_id')->map(fn (Collection $g) => $g->max('date'));
        $to = Carbon::parse($f->to);

        return $rows->groupBy(fn ($r) => "{$r->customer_id}:{$r->product_id}")
            ->map(function (Collection $g) use ($to, $lastByCustomer) {
                $dates = $g->groupBy('order_id')->map(fn (Collection $o) => $o->min('date'))->sort()->values();
                if ($dates->count() < self::LAPSED_MIN_ORDERS) {
                    return null;
                }
                $first = Carbon::parse($dates->first());
                $last = Carbon::parse($dates->last());
                $gap = $first->diffInDays($last) / ($dates->count() - 1);
                $since = (int) $last->diffInDays($to);
                if ($since <= max(self::LAPSED_MIN_DAYS, 2 * $gap)) {
                    return null;
                }
                $r = $g->first();

                return [
                    'customer_id' => $r->customer_id,
                    'customer' => $r->customer,
                    'product_id' => $r->product_id,
                    'sku' => $r->sku,
                    'name' => $r->name,
                    'orders' => $dates->count(),
                    'usual_gap_days' => round($gap, 1),
                    'last_ordered' => $dates->last(),
                    'days_since' => $since,
                    'avg_order_sales' => round($g->sum('sales') / $dates->count(), 2),
                    'still_ordering' => $lastByCustomer[$r->customer_id] > $dates->last(),
                ];
            })
            ->filter()
            ->sortBy([['avg_order_sales', 'desc'], ['customer', 'asc']])->values()->all();
    }

    /** @return array{filename: string, type: string, body: string} */
    public function export(array $r, InsightFilters $f, string $groupBy, string $format): array
    {
        $t = $r['totals'];
        $label = ['sku' => 'SKU', 'range' => 'Range', 'customer' => 'Customer', 'channel' => 'Channel'][$groupBy];
        $groupCols = [$label, 'Units', 'Sales', 'Cost', 'GP', 'GM %', 'Orders'];
        $groupRow = fn ($g) => [$g['label'], $g['units'], $g['sales'], $g['cost'], $g['gp'], $g['gm_percent'], $g['orders']];

        return InsightExport::make('Stock sold', $f, [
            'Dispatched stock, money ex GST. Sales at the order line price ('.$t['estimated'].' line(s) with no price at the default price); cost at dispatch, else today\'s average cost.',
            "Sales {$t['sales']}, cost {$t['cost']}, GP {$t['gp']}, GM {$t['gm_percent']}% on {$t['orders']} order(s), {$t['units']} units.",
        ], [
            ['title' => "By {$label}", 'columns' => $groupCols, 'rows' => array_map($groupRow, $r['groups'][$groupBy]), 'money' => [2, 3, 4]],
            ['title' => 'Week on week', 'columns' => ['Week of', 'Units', 'Sales', 'GP', 'Change in sales %'],
                'rows' => array_map(fn ($w) => [$w['start'], $w['units'], $w['sales'], $w['gp'], $w['change_percent']], $r['trend']), 'money' => [2, 3]],
            ['title' => 'Top 10 products by GP', 'columns' => $groupCols, 'rows' => array_map($groupRow, $r['top']['products']), 'money' => [2, 3, 4]],
            ['title' => 'Bottom 10 products by GP', 'columns' => $groupCols, 'rows' => array_map($groupRow, $r['top']['products_bottom']), 'money' => [2, 3, 4]],
            ['title' => 'Top 10 customers by GP', 'columns' => $groupCols, 'rows' => array_map($groupRow, $r['top']['customers']), 'money' => [2, 3, 4]],
            ['title' => 'Bottom 10 customers by GP', 'columns' => $groupCols, 'rows' => array_map($groupRow, $r['top']['customers_bottom']), 'money' => [2, 3, 4]],
            ['title' => 'Stopped ordering a product they used to buy', 'columns' => ['Customer', 'SKU', 'Product', 'Orders (26 weeks)', 'Usual gap (days)', 'Last ordered', 'Days since', 'Avg sales per order', 'Still ordering other things'],
                'rows' => array_map(fn ($l) => [$l['customer'], $l['sku'], $l['name'], $l['orders'], $l['usual_gap_days'], $l['last_ordered'], $l['days_since'], $l['avg_order_sales'], $l['still_ordering'] ? 'yes' : 'no'], $r['lapsed']),
                'money' => [7]],
        ], $format);
    }
}
