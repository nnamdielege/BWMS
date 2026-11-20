<?php

namespace App\Services\Reports\Insights;

use App\Enums\MovementType;
use App\Models\CountTask;
use App\Models\PickList;
use App\Models\Setting;
use App\Models\Stocktake;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reports → Exceptions & audit: patterns worth a second look (theft indicators). Each flag
 * carries the ids of the movements (or pick lists, stocktakes) behind it.
 *
 * - Adjustments and write-offs by user, reason and hour; after hours (outside the working
 *   hours, Brisbane) and weekends are flagged.
 * - Repeated losses: the same SKU, or the same bin, with REPEAT_MIN or more unexplained losses
 *   (negative stocktake variances, Lost / missing adjustments; see ReconciliationReport).
 * - Bins that went negative: raised by a dispatch in the dates, or negative now.
 * - Short picks (picked less than asked, where there was stock to pick) by picker and SKU,
 *   and picks confirmed without scanning ("Enter quantity") by picker and reason.
 * - Stocktake lines counted and posted by the same person, with a variance worth at least
 *   the large-variance amount.
 */
class ExceptionsReport
{
    public const REPEAT_MIN = 2;

    /** Working hours, Brisbane: "06:00-18:00". Outside them is after hours. */
    public const WORK_HOURS = 'exceptions.work_hours';

    /** A stocktake variance worth this many dollars (either way) is large. */
    public const LARGE_VARIANCE = 'exceptions.large_variance';

    /** @return array{0: int, 1: int} start and end hour, e.g. [6, 18] */
    public static function workHours(): array
    {
        if (preg_match('/^(\d{1,2}):?\d{0,2}\s*-\s*(\d{1,2})/', (string) Setting::get(self::WORK_HOURS, '06:00-18:00'), $m)) {
            return [min(23, (int) $m[1]), min(24, max((int) $m[1] + 1, (int) $m[2]))];
        }

        return [6, 18];
    }

    public static function largeVariance(): float
    {
        return max(0.0, (float) Setting::get(self::LARGE_VARIANCE, '50'));
    }

    /** After hours: before the start hour or from the end hour on. */
    public static function afterHours(Carbon $local, array $hours): bool
    {
        return $local->hour < $hours[0] || $local->hour >= $hours[1];
    }

    public function build(InsightFilters $f): array
    {
        [$start, $end] = $f->window();
        $hours = self::workHours();
        $moves = $this->adjustments($f, $start, $end, $hours);
        $repeats = $this->repeatedLosses($f, $start, $end);
        $negative = $this->negativeBins($f, $start, $end);
        $picks = $this->picks($f, $start, $end);
        $counts = $this->selfCounted($f, $start, $end);
        $flagged = $moves->filter(fn ($m) => $m['after_hours'] || $m['weekend']);

        return [
            'work_hours' => sprintf('%02d:00-%02d:00', $hours[0], $hours[1]),
            'large_variance' => self::largeVariance(),
            'totals' => [
                'adjustments' => $moves->count(),
                'out_of_hours' => $flagged->count(),
                'repeated_skus' => count($repeats['skus']),
                'repeated_bins' => count($repeats['bins']),
                'negative_bins' => count($negative),
                'short_picks' => $picks['short_lines'],
                'unscanned_picks' => $picks['unscanned_lines'],
                'self_counted' => count($counts),
            ],
            'by_user' => $this->summarise($moves, 'user'),
            'by_reason' => $this->summarise($moves, 'reason'),
            'by_hour' => collect(range(0, 23))->map(fn ($h) => [
                'hour' => $h,
                'label' => sprintf('%02d', $h),
                'count' => $moves->where('hour', $h)->count(),
                'after_hours' => $h < $hours[0] || $h >= $hours[1],
            ])->all(),
            'out_of_hours' => $flagged->values()->all(),
            'repeated' => $repeats,
            'negative_bins' => $negative,
            'short_picks' => $picks['short'],
            'unscanned_picks' => $picks['unscanned'],
            'self_counted' => $counts,
        ];
    }

    /** Every adjustment and write-off in the dates, with its local time and flags. */
    private function adjustments(InsightFilters $f, $start, $end, array $hours): Collection
    {
        $tz = config('bwms.business_timezone');
        $q = DB::table('stock_movements as m')
            ->join('products as p', 'p.id', '=', 'm.product_id')
            ->leftJoin('reason_codes as rc', 'rc.id', '=', 'm.reason_code_id')
            ->leftJoin('users as u', 'u.id', '=', 'm.created_by')
            ->leftJoin('locations as l', 'l.id', '=', 'm.location_id')
            ->whereIn('m.type', [MovementType::Adjustment->value, MovementType::WriteOff->value])
            ->whereBetween('m.occurred_at', [$start, $end]);
        $f->apply($q, 'm.product_id', 'm.location_id');

        return $q->orderBy('m.occurred_at')->get([
            'm.id', 'm.type', 'm.qty', 'm.occurred_at', 'm.note', 'm.unit_cost', 'p.avg_cost', 'p.id as product_id', 'p.sku', 'p.name',
            'rc.name as reason', 'u.id as user_id', 'u.name as user', 'l.code as bin',
        ])->map(function ($m) use ($tz, $hours) {
            $local = Carbon::parse($m->occurred_at, 'UTC')->setTimezone($tz);

            return [
                'id' => (int) $m->id,
                'when' => $local->format('Y-m-d H:i'),
                'day' => $local->format('D'),
                'hour' => $local->hour,
                'type' => MovementType::from($m->type)->label(),
                'user_id' => $m->user_id,
                'user' => $m->user ?? 'System',
                'reason' => $m->reason ?? 'No reason',
                'product_id' => (int) $m->product_id,
                'sku' => $m->sku,
                'name' => $m->name,
                'bin' => $m->bin,
                'qty' => (int) $m->qty,
                'value' => round((int) $m->qty * (float) ($m->unit_cost ?? $m->avg_cost), 2),
                'note' => $m->note,
                'after_hours' => self::afterHours($local, $hours),
                'weekend' => $local->isWeekend(),
            ];
        });
    }

    /** @return list<array> per user or reason: count, units and $ out and in, flags; the movement ids */
    private function summarise(Collection $moves, string $by): array
    {
        return $moves->groupBy($by)->map(fn (Collection $g, $label) => [
            'label' => $label,
            'count' => $g->count(),
            'units_out' => (int) -$g->where('qty', '<', 0)->sum('qty'),
            'value_out' => round(-$g->where('qty', '<', 0)->sum('value'), 2),
            'units_in' => (int) $g->where('qty', '>', 0)->sum('qty'),
            'after_hours' => $g->where('after_hours', true)->count(),
            'weekend' => $g->where('weekend', true)->count(),
            'ids' => $g->pluck('id')->all(),
            'flag_ids' => $g->filter(fn ($m) => $m['after_hours'] || $m['weekend'])->pluck('id')->values()->all(),
        ])->sortByDesc('value_out')->values()->all();
    }

    /** @return array{skus: list<array>, bins: list<array>} the same SKU or bin losing stock again and again */
    private function repeatedLosses(InsightFilters $f, $start, $end): array
    {
        $q = DB::table('stock_movements as m')
            ->join('products as p', 'p.id', '=', 'm.product_id')
            ->join('locations as l', 'l.id', '=', 'm.location_id')
            ->leftJoin('locations as z', 'z.id', '=', 'l.parent_id')
            ->leftJoin('reason_codes as rc', 'rc.id', '=', 'm.reason_code_id')
            ->whereIn('m.type', [MovementType::StocktakeVariance->value, MovementType::Adjustment->value])
            ->where('m.qty', '<', 0)
            ->whereBetween('m.occurred_at', [$start, $end]);
        $f->apply($q, 'm.product_id', 'm.location_id');
        $losses = $q->get(['m.id', 'm.type', 'm.qty', 'm.note', 'm.unit_cost', 'p.avg_cost', 'p.id as product_id', 'p.sku', 'p.name',
            'l.id as location_id', 'l.code as bin', 'z.code as zone', 'rc.code'])
            ->filter(fn ($m) => ReconciliationReport::unexplained($m->type, (int) $m->qty, $m->code, $m->note));

        $group = fn (string $key, callable $label) => $losses->groupBy($key)
            ->filter(fn (Collection $g) => $g->count() >= self::REPEAT_MIN)
            ->map(fn (Collection $g) => $label($g->first()) + [
                'times' => $g->count(),
                'units' => (int) -$g->sum('qty'),
                'value' => round($g->sum(fn ($m) => -$m->qty * (float) ($m->unit_cost ?? $m->avg_cost)), 2),
                'ids' => $g->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            ])
            ->sortByDesc('value')->values()->all();

        return [
            'skus' => $group('product_id', fn ($m) => ['product_id' => (int) $m->product_id, 'sku' => $m->sku, 'name' => $m->name]),
            'bins' => $group('location_id', fn ($m) => ['location_id' => (int) $m->location_id, 'bin' => trim(($m->zone ? "{$m->zone} / " : '').$m->bin)]),
        ];
    }

    /** @return list<array> bins that went negative on dispatch in the dates, or are negative now */
    private function negativeBins(InsightFilters $f, $start, $end): array
    {
        $tz = config('bwms.business_timezone');
        $raised = DB::table('count_tasks as t')
            ->join('locations as l', 'l.id', '=', 't.location_id')
            ->join('products as p', 'p.id', '=', 't.product_id')
            ->where('t.reason', CountTask::REASON_NEGATIVE_AFTER_DISPATCH)
            ->where(fn ($q) => $q->whereBetween('t.created_at', [$start, $end])->orWhereBetween('t.updated_at', [$start, $end]));
        $f->apply($raised, 't.product_id', 't.location_id');
        $now = DB::table('stock_balances as b')
            ->join('locations as l', 'l.id', '=', 'b.location_id')
            ->join('products as p', 'p.id', '=', 'b.product_id')
            ->where('b.on_hand', '<', 0);
        $f->apply($now, 'b.product_id', 'b.location_id');

        $rows = [];
        foreach ($raised->get(['t.location_id', 't.product_id', 'l.code as bin', 'p.sku', 'p.name', 't.on_hand_at_raise', 't.times_raised', 't.status', 't.updated_at']) as $t) {
            $rows["{$t->location_id}:{$t->product_id}"] = [
                'location_id' => (int) $t->location_id, 'product_id' => (int) $t->product_id, 'bin' => $t->bin, 'sku' => $t->sku, 'name' => $t->name,
                'lowest' => (int) $t->on_hand_at_raise, 'times' => (int) $t->times_raised, 'on_hand_now' => null,
                'last' => Carbon::parse($t->updated_at, 'UTC')->setTimezone($tz)->format('Y-m-d H:i'), 'count_task' => $t->status,
            ];
        }
        foreach ($now->groupBy('b.location_id', 'b.product_id', 'l.code', 'p.sku', 'p.name')
            ->get(['b.location_id', 'b.product_id', 'l.code as bin', 'p.sku', 'p.name', DB::raw('SUM(b.on_hand) as on_hand')]) as $b) {
            $k = "{$b->location_id}:{$b->product_id}";
            $rows[$k] = ($rows[$k] ?? ['location_id' => (int) $b->location_id, 'product_id' => (int) $b->product_id, 'bin' => $b->bin, 'sku' => $b->sku,
                'name' => $b->name, 'lowest' => (int) $b->on_hand, 'times' => 0, 'last' => null, 'count_task' => null]);
            $rows[$k]['on_hand_now'] = (int) $b->on_hand;
        }
        // The movements behind each: everything at that bin for that SKU in the dates.
        foreach ($rows as $k => $r) {
            $rows[$k]['ids'] = DB::table('stock_movements')->where('location_id', $r['location_id'])->where('product_id', $r['product_id'])
                ->whereBetween('occurred_at', [$start, $end])->orderBy('id')->limit(500)->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        return collect($rows)->sortBy('lowest')->values()->all();
    }

    /** @return array{short: list<array>, unscanned: list<array>, short_lines: int, unscanned_lines: int} */
    private function picks(InsightFilters $f, $start, $end): array
    {
        $q = DB::table('pick_list_lines as pll')
            ->join('pick_lists as pl', 'pl.id', '=', 'pll.pick_list_id')
            ->join('products as p', 'p.id', '=', 'pll.product_id')
            ->leftJoin('users as u', 'u.id', '=', 'pll.picked_by')
            ->where('pl.status', PickList::STATUS_DISPATCHED)
            ->whereBetween('pl.dispatched_at', [$start, $end])
            ->where('pll.no_stock', false)
            ->when($f->siteId, fn ($q, $id) => $q->where('pl.site_id', $id));
        $f->apply($q, 'pll.product_id');
        $lines = $q->get(['pll.id', 'pl.id as pick_list_id', 'pl.number', 'p.id as product_id', 'p.sku', 'p.name', 'u.name as picker',
            'pll.qty_to_pick_base', 'pll.qty_picked_base', 'pll.override_reason']);

        $short = $lines->filter(fn ($l) => (int) $l->qty_picked_base < (int) $l->qty_to_pick_base);
        $unscanned = $lines->whereNotNull('override_reason');
        $per = fn (Collection $g, callable $label) => $label($g->first()) + [
            'lines' => $g->count(),
            'pick_lists' => $g->pluck('number')->unique()->values()->all(),
            'pick_list_ids' => $g->pluck('pick_list_id')->unique()->values()->all(),
        ];

        return [
            'short_lines' => $short->count(),
            'unscanned_lines' => $unscanned->count(),
            'short' => $short->groupBy(fn ($l) => ($l->picker ?? 'Unknown').'|'.$l->product_id)
                ->map(fn (Collection $g) => $per($g, fn ($l) => ['picker' => $l->picker ?? 'Unknown', 'product_id' => (int) $l->product_id, 'sku' => $l->sku, 'name' => $l->name])
                    + ['units_short' => (int) $g->sum(fn ($l) => $l->qty_to_pick_base - $l->qty_picked_base)])
                ->sortByDesc('units_short')->values()->all(),
            'unscanned' => $unscanned->groupBy(fn ($l) => ($l->picker ?? 'Unknown').'|'.$l->override_reason)
                ->map(fn (Collection $g) => $per($g, fn ($l) => ['picker' => $l->picker ?? 'Unknown', 'reason' => str_replace('_', ' ', $l->override_reason)])
                    + ['skus' => $g->pluck('sku')->unique()->values()->all()])
                ->sortByDesc('lines')->values()->all(),
        ];
    }

    /** @return list<array> stocktake lines counted and posted by the same person, with a large variance */
    private function selfCounted(InsightFilters $f, $start, $end): array
    {
        $min = self::largeVariance();
        $q = DB::table('stocktake_lines as sl')
            ->join('stocktakes as s', 's.id', '=', 'sl.stocktake_id')
            ->join('products as p', 'p.id', '=', 'sl.product_id')
            ->join('locations as l', 'l.id', '=', 'sl.location_id')
            ->join('users as u', 'u.id', '=', 's.posted_by')
            ->leftJoin('stock_movements as m', 'm.idempotency_key', '=', DB::raw("CONCAT('stk:', s.id, ':line:', sl.id)"))
            ->where('s.status', Stocktake::STATUS_POSTED)
            ->whereBetween('s.posted_at', [$start, $end])
            ->whereColumn('sl.counted_by', 's.posted_by')
            ->where('sl.posted_variance', '!=', 0);
        $f->apply($q, 'sl.product_id', 'sl.location_id');

        return $q->get(['s.id as stocktake_id', 's.number', 'u.name as user', 'p.id as product_id', 'p.sku', 'p.name', 'l.code as bin',
            'sl.posted_variance', 'm.id as movement_id', 'm.unit_cost', 'p.avg_cost'])
            ->map(fn ($l) => [
                'stocktake_id' => (int) $l->stocktake_id,
                'stocktake' => $l->number,
                'user' => $l->user,
                'product_id' => (int) $l->product_id,
                'sku' => $l->sku,
                'name' => $l->name,
                'bin' => $l->bin,
                'variance' => (int) $l->posted_variance,
                'value' => round((int) $l->posted_variance * (float) ($l->unit_cost ?? $l->avg_cost), 2),
                'ids' => $l->movement_id ? [(int) $l->movement_id] : [],
            ])
            ->filter(fn ($l) => abs($l['value']) >= $min)
            ->sortBy('value')->values()->all();
    }

    /** @return array{filename: string, type: string, body: string} */
    public function export(array $r, InsightFilters $f, string $format): array
    {
        $t = $r['totals'];
        $join = fn (array $a) => implode(', ', $a);

        return InsightExport::make('Exceptions and audit', $f, [
            "Working hours {$r['work_hours']} (Brisbane); outside them, or at the weekend, is flagged. A large stocktake variance is \${$r['large_variance']} or more.",
            "Adjustments/write-offs {$t['adjustments']} ({$t['out_of_hours']} out of hours); repeated losses: {$t['repeated_skus']} SKU(s), {$t['repeated_bins']} bin(s); bins negative {$t['negative_bins']}; short picks {$t['short_picks']}; unscanned picks {$t['unscanned_picks']}; self-counted large variances {$t['self_counted']}.",
        ], [
            ['title' => 'Adjustments and write-offs by user', 'columns' => ['User', 'Count', 'Units out', '$ out', 'Units in', 'After hours', 'Weekend'],
                'rows' => array_map(fn ($u) => [$u['label'], $u['count'], $u['units_out'], $u['value_out'], $u['units_in'], $u['after_hours'], $u['weekend']], $r['by_user']), 'money' => [3]],
            ['title' => 'By reason', 'columns' => ['Reason', 'Count', 'Units out', '$ out', 'Units in', 'After hours', 'Weekend'],
                'rows' => array_map(fn ($u) => [$u['label'], $u['count'], $u['units_out'], $u['value_out'], $u['units_in'], $u['after_hours'], $u['weekend']], $r['by_reason']), 'money' => [3]],
            ['title' => 'By hour of day', 'columns' => ['Hour', 'Count', 'After hours'],
                'rows' => array_map(fn ($h) => [$h['label'], $h['count'], $h['after_hours'] ? 'yes' : ''], $r['by_hour'])],
            ['title' => 'After hours or at the weekend', 'columns' => ['When', 'Day', 'User', 'Type', 'Reason', 'SKU', 'Product', 'Bin', 'Qty', 'Value', 'Note', 'Movement'],
                'rows' => array_map(fn ($m) => [$m['when'], $m['day'], $m['user'], $m['type'], $m['reason'], $m['sku'], $m['name'], $m['bin'], $m['qty'], $m['value'], $m['note'], $m['id']], $r['out_of_hours']), 'money' => [9]],
            ['title' => 'Repeated losses on the same SKU', 'columns' => ['SKU', 'Product', 'Times', 'Units', 'Value', 'Movements'],
                'rows' => array_map(fn ($x) => [$x['sku'], $x['name'], $x['times'], $x['units'], $x['value'], $join($x['ids'])], $r['repeated']['skus']), 'money' => [4]],
            ['title' => 'Repeated losses in the same bin', 'columns' => ['Bin', 'Times', 'Units', 'Value', 'Movements'],
                'rows' => array_map(fn ($x) => [$x['bin'], $x['times'], $x['units'], $x['value'], $join($x['ids'])], $r['repeated']['bins']), 'money' => [3]],
            ['title' => 'Bins that went negative', 'columns' => ['Bin', 'SKU', 'Product', 'Lowest', 'Times raised', 'On hand now', 'Last', 'Count task'],
                'rows' => array_map(fn ($x) => [$x['bin'], $x['sku'], $x['name'], $x['lowest'], $x['times'], $x['on_hand_now'], $x['last'], $x['count_task']], $r['negative_bins'])],
            ['title' => 'Short picks by picker and SKU', 'columns' => ['Picker', 'SKU', 'Product', 'Lines', 'Units short', 'Pick lists'],
                'rows' => array_map(fn ($x) => [$x['picker'], $x['sku'], $x['name'], $x['lines'], $x['units_short'], $join($x['pick_lists'])], $r['short_picks'])],
            ['title' => 'Picks entered without scanning', 'columns' => ['Picker', 'Reason', 'Lines', 'SKUs', 'Pick lists'],
                'rows' => array_map(fn ($x) => [$x['picker'], $x['reason'], $x['lines'], $join($x['skus']), $join($x['pick_lists'])], $r['unscanned_picks'])],
            ['title' => 'Counted and posted by the same person, large variance', 'columns' => ['Stocktake', 'User', 'SKU', 'Product', 'Bin', 'Variance', 'Value'],
                'rows' => array_map(fn ($x) => [$x['stocktake'], $x['user'], $x['sku'], $x['name'], $x['bin'], $x['variance'], $x['value']], $r['self_counted']), 'money' => [6]],
        ], $format);
    }
}
