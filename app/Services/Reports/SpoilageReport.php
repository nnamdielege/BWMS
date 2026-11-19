<?php

namespace App\Services\Reports;

use App\Enums\MovementType;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Services\Documents\CsvWriter;
use App\Services\Documents\XlsxWriter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reports → Spoilage: every write-off between two dates (Brisbane), money ex GST.
 *
 * Each write-off is valued at the cost stored on its movement (the average cost when
 * it was written off). Older write-offs have none: they fall back to today's average
 * cost and are marked. Spoilage % is the write-offs' value over the cost of goods sold
 * (dispatches less reversals, at their own movement cost, else today's) in the same dates.
 */
class SpoilageReport
{
    public const GROUPS = ['reason', 'product', 'range', 'supplier'];

    public const PERIODS = ['week', 'month'];

    public const FORMATS = ['xlsx', 'csv'];

    /** How a line was valued. */
    public const COST_MOVEMENT = 'movement';

    public const COST_CURRENT = 'current';

    public const COST_NONE = 'none';

    /** @return array{rows: list<array>, totals: array, groups: array<string, list<array>>, trend: list<array>, top: list<array>, period: string} */
    public function build(string $from, string $to, string $period = 'week'): array
    {
        [$start, $end] = $this->window($from, $to);
        $rows = $this->rows($start, $end);
        $value = round(array_sum(array_column($rows, 'value')), 2);
        $cogs = $this->costOfGoodsSold($start, $end);

        $groups = [];
        foreach (self::GROUPS as $by) {
            $groups[$by] = $this->group($rows, $by, $value);
        }

        return [
            'rows' => $rows,
            'totals' => [
                'value' => $value,
                'units' => array_sum(array_column($rows, 'units')),
                'lines' => count($rows),
                'at_current_cost' => count(array_filter($rows, fn ($r) => $r['cost_basis'] === self::COST_CURRENT)),
                'no_cost' => count(array_filter($rows, fn ($r) => $r['cost_basis'] === self::COST_NONE)),
                'cogs' => $cogs,
                'percent_of_cogs' => $cogs > 0 ? round($value / $cogs * 100, 2) : null,
            ],
            'groups' => $groups,
            'trend' => $this->trend($rows, $from, $to, $period),
            'top' => array_slice($groups['product'], 0, 10),
            'period' => $period,
        ];
    }

    /** Weekly for up to about four months, else monthly. */
    public static function defaultPeriod(string $from, string $to): string
    {
        return Carbon::parse($from)->diffInDays(Carbon::parse($to)) > 120 ? 'month' : 'week';
    }

    /** @return list<array> one row per write-off movement, oldest first */
    private function rows(Carbon $start, Carbon $end): array
    {
        $tz = config('bwms.business_timezone');
        $moves = StockMovement::where('type', MovementType::WriteOff)
            ->whereBetween('occurred_at', [$start, $end])
            ->with([
                'product' => fn ($q) => $q->withTrashed()->select(['id', 'sku', 'name', 'base_unit', 'avg_cost', 'range_id', 'supplier_id']),
                'product.range:id,name', 'batch:id,lot_code,best_before,supplier_id', 'reasonCode:id,code,name',
                'location.parent', 'creator:id,name',
            ])
            ->orderBy('occurred_at')->orderBy('id')->get();

        $supplierIds = $moves->map(fn (StockMovement $m) => $m->batch?->supplier_id ?? $m->product?->supplier_id)->filter()->unique()->values();
        $suppliers = Supplier::whereIn('id', $supplierIds)->pluck('name', 'id');

        return $moves->map(function (StockMovement $m) use ($tz, $suppliers) {
            [$cost, $basis] = self::cost($m->unit_cost, $m->product?->avg_cost);
            $units = -$m->qty;
            $supplierId = $m->batch?->supplier_id ?? $m->product?->supplier_id;

            return [
                'id' => $m->id,
                'date' => $m->occurred_at->setTimezone($tz)->toDateString(),
                'product_id' => $m->product_id,
                'sku' => $m->product?->sku,
                'name' => $m->product?->name,
                'base_unit' => $m->product?->base_unit,
                'range_id' => $m->product?->range_id,
                'range' => $m->product?->range?->name,
                'supplier_id' => $supplierId,
                'supplier' => $supplierId ? $suppliers[$supplierId] ?? null : null,
                'reason_id' => $m->reason_code_id,
                'reason' => $m->reasonCode?->name,
                'bin' => $m->location?->path(),
                'batch' => $m->batch?->lot_code,
                'best_before' => $m->batch?->best_before?->toDateString(),
                'units' => $units,
                'unit_cost' => $cost,
                'value' => round($units * ($cost ?? 0), 2),
                'cost_basis' => $basis,
                'note' => $m->note,
                'by' => $m->creator?->name,
            ];
        })->values()->all();
    }

    /**
     * The cost a line is valued at: the movement's own, else today's average cost.
     *
     * @return array{0: ?float, 1: string} cost per base unit, basis
     */
    public static function cost(mixed $movementCost, mixed $avgCost): array
    {
        if ($movementCost !== null) {
            return [(float) $movementCost, self::COST_MOVEMENT];
        }
        if ((float) $avgCost > 0) {
            return [(float) $avgCost, self::COST_CURRENT];
        }

        return [null, self::COST_NONE];
    }

    /** Dispatches less reversals, at the cost on each movement, else today's average cost. */
    private function costOfGoodsSold(Carbon $start, Carbon $end): float
    {
        $sum = DB::table('stock_movements')
            ->join('products', 'products.id', '=', 'stock_movements.product_id')
            ->whereIn('stock_movements.type', [MovementType::Sale->value, MovementType::SaleReversal->value])
            ->whereBetween('stock_movements.occurred_at', [$start, $end])
            ->sum(DB::raw('-stock_movements.qty * COALESCE(stock_movements.unit_cost, products.avg_cost)'));

        return round((float) $sum, 2);
    }

    /** @return list<array{key: string, label: string, value: float, units: int, lines: int, share: ?float}> biggest loss first */
    private function group(array $rows, string $by, float $total): array
    {
        [$key, $label, $none] = match ($by) {
            'reason' => ['reason_id', 'reason', 'No reason'],
            'product' => ['product_id', 'name', 'Unknown product'],
            'range' => ['range_id', 'range', 'No range'],
            'supplier' => ['supplier_id', 'supplier', 'No supplier'],
        };

        return collect($rows)->groupBy(fn ($r) => (string) ($r[$key] ?? ''))
            ->map(fn (Collection $g, string $k) => [
                'key' => $k,
                'label' => $by === 'product' ? trim(($g[0]['sku'] ?? '').' '.($g[0]['name'] ?? $none)) : ($g[0][$label] ?? $none),
                'value' => round($g->sum('value'), 2),
                'units' => (int) $g->sum('units'),
                'lines' => $g->count(),
                'share' => $total > 0 ? round($g->sum('value') / $total * 100, 1) : null,
            ])
            ->sortBy([['value', 'desc'], ['label', 'asc']])->values()->all();
    }

    /** @return list<array{start: string, label: string, value: float, units: int}> every week (Monday) or month in the dates, empty ones included */
    private function trend(array $rows, string $from, string $to, string $period): array
    {
        $bucket = fn (string $date) => $period === 'month'
            ? Carbon::parse($date)->startOfMonth()->toDateString()
            : Carbon::parse($date)->startOfWeek(Carbon::MONDAY)->toDateString();

        $buckets = [];
        $last = Carbon::parse($bucket($to));
        for ($d = Carbon::parse($bucket($from)); $d->lte($last); $period === 'month' ? $d->addMonthNoOverflow() : $d->addWeek()) {
            $buckets[$d->toDateString()] = [
                'start' => $d->toDateString(),
                'label' => $period === 'month' ? $d->format('M Y') : $d->format('j M'),
                'value' => 0.0,
                'units' => 0,
            ];
        }
        foreach ($rows as $r) {
            $b = $bucket($r['date']);
            $buckets[$b]['value'] = round($buckets[$b]['value'] + $r['value'], 2);
            $buckets[$b]['units'] += $r['units'];
        }

        return array_values($buckets);
    }

    /** @return array{0: Carbon, 1: Carbon} the dates as UTC instants */
    private function window(string $from, string $to): array
    {
        $tz = config('bwms.business_timezone');

        return [Carbon::parse($from, $tz)->startOfDay()->utc(), Carbon::parse($to, $tz)->endOfDay()->utc()];
    }

    /** @return array{filename: string, type: string, body: string} */
    public function export(array $report, string $from, string $to, string $groupBy, string $format): array
    {
        $t = $report['totals'];
        $header = [
            ['Spoilage', "{$from} to {$to}"],
            ['Money ex GST. Each write-off at the average cost when it was written off; "today" = no cost was kept, valued at today\'s average cost.'],
        ];
        $summary = [
            ['Total written off', $t['value']],
            ['Units', $t['units']],
            ['Cost of goods sold', $t['cogs']],
            ['Spoilage % of cost of goods sold', $t['percent_of_cogs']],
            [],
            ['By '.$groupBy, 'Value', 'Units', 'Lines', 'Share %'],
            ...array_map(fn ($g) => [$g['label'], $g['value'], $g['units'], $g['lines'], $g['share']], $report['groups'][$groupBy]),
            [],
        ];
        $columns = ['Date', 'SKU', 'Product', 'Range', 'Supplier', 'Reason', 'Bin', 'Batch', 'Best before', 'Units', 'Cost / unit', 'Value', 'Cost at', 'Note', 'By'];
        $rows = array_map(fn ($r) => [$r['date'], $r['sku'], $r['name'], $r['range'], $r['supplier'], $r['reason'], $r['bin'], $r['batch'], $r['best_before'],
            $r['units'], $r['unit_cost'], $r['value'], match ($r['cost_basis']) {
                self::COST_MOVEMENT => 'write-off',
                self::COST_CURRENT => 'today',
                default => 'no cost',
            }, $r['note'], $r['by']], $report['rows']);
        $rows[] = ['Total', '', '', '', '', '', '', '', '', $t['units'], '', $t['value']];

        if ($format === 'csv') {
            $body = CsvWriter::toString([...$header, [], ...$summary, $columns, ...$rows]);
            $type = 'text/csv; charset=UTF-8';
        } else {
            $xlsx = (new XlsxWriter('Spoilage'))->landscape()->widths([12, 12, 30, 16, 20, 20, 18, 12, 12, 8, 10, 10, 10, 30, 14])
                ->costColumns([10])->moneyColumns([11])->footer('&LSpoilage&RPage &P of &N');
            foreach ($header as $h) {
                $xlsx->row($h, true);
            }
            $xlsx->row([]);
            foreach ($summary as $i => $s) {
                $xlsx->row($s, $i === 5);
            }
            $xlsx->headerRow($columns);
            foreach ($rows as $r) {
                $xlsx->row($r, $r[0] === 'Total');
            }
            $body = $xlsx->toString();
            $type = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        }

        return ['filename' => "spoilage-{$from}-to-{$to}.{$format}", 'type' => $type, 'body' => $body];
    }
}
