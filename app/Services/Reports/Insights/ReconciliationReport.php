<?php

namespace App\Services\Reports\Insights;

use App\Enums\MovementType;
use App\Models\ReasonCode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reports → Stock reconciliation: per SKU over the dates (base units),
 *
 *   opening + received − dispatched − write-offs − give-aways ± adjustments ± stocktake ± other = expected closing
 *
 * against the actual closing (today's balances, less everything posted after the end date).
 * "Other" is transfers in and out of the site, returns to suppliers, customer returns and
 * opening balances. The two closings agree unless the balances and the ledger disagree, which
 * is shown as a difference to look into.
 *
 * Unexplained loss (the shrinkage / theft indicator) = negative stocktake variances, plus
 * negative adjustments with no supporting reason (see unexplained()), in units and at the cost
 * on each movement (else today's average cost). Shrinkage % = unexplained loss ÷ cost of goods
 * sold (dispatches) in the same dates, per range and overall.
 */
class ReconciliationReport
{
    /** Adjustment reasons that explain nothing on their own. */
    public const UNSUPPORTED_REASONS = ['LOST'];

    /** Adjustment reasons that move stock between labels, never lose it. */
    public const NEUTRAL_REASONS = ['BATCH_ENABLE'];

    /**
     * Is this movement unexplained loss? Negative stocktake variances always are. A negative
     * adjustment is, when it has no reason, a reason that explains nothing ("Lost / missing"),
     * or no note. Write-offs, give-aways and everything else are explained by their type.
     */
    public static function unexplained(MovementType|string $type, int $qty, ?string $reasonCode, ?string $note): bool
    {
        $type = $type instanceof MovementType ? $type : MovementType::from($type);
        if ($qty >= 0) {
            return false;
        }

        return match ($type) {
            MovementType::StocktakeVariance => true,
            MovementType::Adjustment => ! in_array($reasonCode, self::NEUTRAL_REASONS, true)
                && ($reasonCode === null || in_array($reasonCode, self::UNSUPPORTED_REASONS, true) || trim((string) $note) === ''),
            default => false,
        };
    }

    /** opening + received − dispatched − write-offs − give-aways + adjustments + stocktake + other */
    public static function expectedClosing(array $r): int
    {
        return $r['opening'] + $r['received'] - $r['dispatched'] - $r['write_offs'] - $r['giveaways'] + $r['adjustments'] + $r['stocktake'] + $r['other'];
    }

    public function build(InsightFilters $f): array
    {
        [$start, $end] = $f->window();
        $flows = $this->flows($f, $start, $end);
        $actual = $this->actualClosing($f, $end);
        $loss = $this->unexplainedLoss($f, $start, $end);
        $cogs = $this->cogs($f, $start, $end);

        $ids = $flows->keys()->merge($actual->keys())->unique()->values();
        $products = DB::table('products as p')->leftJoin('ranges as r', 'r.id', '=', 'p.range_id')->whereIn('p.id', $ids)
            ->get(['p.id', 'p.sku', 'p.name', 'p.base_unit', 'p.range_id', 'r.name as range', 'p.avg_cost'])->keyBy('id');

        $rows = $ids->map(function ($id) use ($flows, $actual, $loss, $cogs, $products) {
            $p = $products[$id] ?? null;
            $fl = $flows[$id] ?? null;
            $r = [
                'product_id' => (int) $id,
                'sku' => $p?->sku,
                'name' => $p?->name,
                'base_unit' => $p?->base_unit,
                'range_id' => $p?->range_id,
                'range' => $p?->range ?? 'No range',
            ];
            foreach (['opening', 'received', 'dispatched', 'write_offs', 'giveaways', 'adjustments', 'stocktake', 'other'] as $k) {
                $r[$k] = (int) ($fl->{$k} ?? 0);
            }
            $r['expected'] = self::expectedClosing($r);
            $r['actual'] = (int) ($actual[$id] ?? 0);
            $r['difference'] = $r['actual'] - $r['expected'];
            $r['actual_value'] = round($r['actual'] * (float) ($p?->avg_cost ?? 0), 2);
            $l = $loss[$id] ?? ['units' => 0, 'value' => 0.0, 'ids' => []];
            $r['loss_units'] = $l['units'];
            $r['loss_value'] = $l['value'];
            $r['loss_movement_ids'] = $l['ids'];
            $r['cogs'] = $cogs[$id] ?? 0.0;
            $r['shrinkage_percent'] = $r['cogs'] > 0 ? round($r['loss_value'] / $r['cogs'] * 100, 2) : null;

            return $r;
        })
            ->filter(fn ($r) => $r['opening'] || $r['actual'] || $r['expected'] || $r['received'] || $r['dispatched'] || $r['loss_units'] || $r['difference'])
            ->sortBy([['loss_value', 'desc'], ['sku', 'asc']])->values();

        $ranges = $rows->groupBy('range')->map(fn (Collection $g, $range) => [
            'range' => $range,
            'loss_units' => (int) $g->sum('loss_units'),
            'loss_value' => round($g->sum('loss_value'), 2),
            'cogs' => round($g->sum('cogs'), 2),
        ] + ['shrinkage_percent' => $g->sum('cogs') > 0 ? round($g->sum('loss_value') / $g->sum('cogs') * 100, 2) : null])
            ->sortByDesc('loss_value')->values();

        $lossValue = round($rows->sum('loss_value'), 2);
        $cogsTotal = round($rows->sum('cogs'), 2);

        return [
            'rows' => $rows->all(),
            'ranges' => $ranges->all(),
            'totals' => [
                'products' => $rows->count(),
                'loss_units' => (int) $rows->sum('loss_units'),
                'loss_value' => $lossValue,
                'cogs' => $cogsTotal,
                'shrinkage_percent' => $cogsTotal > 0 ? round($lossValue / $cogsTotal * 100, 2) : null,
                'mismatched' => $rows->where('difference', '!=', 0)->count(),
            ],
            'top' => $rows->where('loss_value', '>', 0)->take(10)->values()->all(),
        ];
    }

    /** @return Collection<int, object> per product: opening and each flow in the dates */
    private function flows(InsightFilters $f, $start, $end): Collection
    {
        $give = "'".implode("','", ReasonCode::GIVEAWAY_CODES)."'";
        $in = 'm.occurred_at >= ?';
        $sum = fn (string $when) => "SUM(CASE WHEN {$in} AND ({$when}) THEN m.qty ELSE 0 END)";
        $typ = fn (MovementType ...$t) => 'm.type IN ('.implode(',', array_map(fn ($x) => "'{$x->value}'", $t)).')';

        $q = DB::table('stock_movements as m')->leftJoin('reason_codes as rc', 'rc.id', '=', 'm.reason_code_id')
            ->where('m.occurred_at', '<=', $end)
            ->groupBy('m.product_id')
            ->selectRaw('m.product_id')
            ->selectRaw('SUM(CASE WHEN m.occurred_at < ? THEN m.qty ELSE 0 END) as opening', [$start])
            ->selectRaw($sum($typ(MovementType::Receipt)).' as received', [$start])
            ->selectRaw('-'.$sum($typ(MovementType::Sale, MovementType::SaleReversal)).' as dispatched', [$start])
            ->selectRaw('-'.$sum($typ(MovementType::WriteOff)." AND (rc.code IS NULL OR rc.code NOT IN ({$give}))").' as write_offs', [$start])
            ->selectRaw('-'.$sum($typ(MovementType::WriteOff)." AND rc.code IN ({$give})").' as giveaways', [$start])
            ->selectRaw($sum($typ(MovementType::Adjustment)).' as adjustments', [$start])
            ->selectRaw($sum($typ(MovementType::StocktakeVariance)).' as stocktake', [$start])
            ->selectRaw($sum($typ(MovementType::TransferIn, MovementType::TransferOut, MovementType::OpeningBalance, MovementType::ReturnToSupplier, MovementType::CustomerReturn)).' as other', [$start]);
        $f->apply($q, 'm.product_id', 'm.location_id');

        return $q->get()->keyBy('product_id');
    }

    /** @return Collection<int, int> per product: today's balance less everything posted after the end */
    private function actualClosing(InsightFilters $f, $end): Collection
    {
        $now = $f->apply(DB::table('stock_balances')->groupBy('product_id')->selectRaw('product_id, SUM(on_hand) as qty'), 'product_id', 'location_id')
            ->pluck('qty', 'product_id');
        $after = $f->apply(DB::table('stock_movements')->where('occurred_at', '>', $end)->groupBy('product_id')->selectRaw('product_id, SUM(qty) as qty'), 'product_id', 'location_id')
            ->pluck('qty', 'product_id');

        return $now->keys()->merge($after->keys())->unique()->mapWithKeys(fn ($id) => [$id => (int) ($now[$id] ?? 0) - (int) ($after[$id] ?? 0)]);
    }

    /** @return array<int, array{units: int, value: float, ids: list<int>}> */
    private function unexplainedLoss(InsightFilters $f, $start, $end): array
    {
        $q = DB::table('stock_movements as m')->join('products as p', 'p.id', '=', 'm.product_id')
            ->leftJoin('reason_codes as rc', 'rc.id', '=', 'm.reason_code_id')
            ->whereIn('m.type', [MovementType::StocktakeVariance->value, MovementType::Adjustment->value])
            ->where('m.qty', '<', 0)
            ->whereBetween('m.occurred_at', [$start, $end]);
        $f->apply($q, 'm.product_id', 'm.location_id');

        $out = [];
        foreach ($q->get(['m.id', 'm.product_id', 'm.type', 'm.qty', 'm.note', 'm.unit_cost', 'p.avg_cost', 'rc.code']) as $m) {
            if (! self::unexplained($m->type, (int) $m->qty, $m->code, $m->note)) {
                continue;
            }
            $o = $out[$m->product_id] ??= ['units' => 0, 'value' => 0.0, 'ids' => []];
            $o['units'] += -$m->qty;
            $o['value'] = round($o['value'] + -$m->qty * (float) ($m->unit_cost ?? $m->avg_cost), 2);
            $o['ids'][] = (int) $m->id;
            $out[$m->product_id] = $o;
        }

        return $out;
    }

    /** @return array<int, float> per product: dispatches less reversals at their cost, else today's */
    private function cogs(InsightFilters $f, $start, $end): array
    {
        $q = DB::table('stock_movements as m')->join('products as p', 'p.id', '=', 'm.product_id')
            ->whereIn('m.type', [MovementType::Sale->value, MovementType::SaleReversal->value])
            ->whereBetween('m.occurred_at', [$start, $end])
            ->groupBy('m.product_id')
            ->selectRaw('m.product_id, SUM(-m.qty * COALESCE(m.unit_cost, p.avg_cost)) as cogs');
        $f->apply($q, 'm.product_id', 'm.location_id');

        return $q->pluck('cogs', 'product_id')->map(fn ($v) => round((float) $v, 2))->all();
    }

    /** @return array{filename: string, type: string, body: string} */
    public function export(array $r, InsightFilters $f, string $format): array
    {
        $t = $r['totals'];

        return InsightExport::make('Stock reconciliation', $f, [
            'Base units. Expected closing = opening + received − dispatched − write-offs − give-aways ± adjustments ± stocktake ± other (transfers, returns). Actual = the ledger balance at the end date.',
            'Unexplained loss = negative stocktake variances + negative adjustments with no supporting reason (no reason, "Lost / missing", or no note), at cost. Shrinkage % = unexplained loss ÷ cost of goods sold.',
            "Unexplained loss {$t['loss_units']} units, {$t['loss_value']} at cost; cost of goods sold {$t['cogs']}; shrinkage {$t['shrinkage_percent']}%.",
        ], [
            [
                'title' => 'Shrinkage by range',
                'columns' => ['Range', 'Unexplained units', 'Unexplained $', 'Cost of goods sold', 'Shrinkage %'],
                'rows' => [...array_map(fn ($g) => [$g['range'], $g['loss_units'], $g['loss_value'], $g['cogs'], $g['shrinkage_percent']], $r['ranges']),
                    ['Total', $t['loss_units'], $t['loss_value'], $t['cogs'], $t['shrinkage_percent']]],
                'money' => [2, 3],
            ],
            [
                'title' => 'Per SKU, biggest unexplained loss first',
                'columns' => ['SKU', 'Product', 'Range', 'Opening', 'Received', 'Dispatched', 'Write-offs', 'Give-aways', 'Adjustments', 'Stocktake', 'Other',
                    'Expected closing', 'Actual closing', 'Difference', 'Unexplained units', 'Unexplained $', 'Shrinkage %'],
                'rows' => array_map(fn ($x) => [$x['sku'], $x['name'], $x['range'], $x['opening'], $x['received'], $x['dispatched'], $x['write_offs'], $x['giveaways'],
                    $x['adjustments'], $x['stocktake'], $x['other'], $x['expected'], $x['actual'], $x['difference'], $x['loss_units'], $x['loss_value'], $x['shrinkage_percent']], $r['rows']),
                'money' => [15],
            ],
        ], $format);
    }
}
