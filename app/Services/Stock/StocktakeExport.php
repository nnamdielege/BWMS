<?php

namespace App\Services\Stock;

use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Stocktake;
use App\Models\StocktakeLine;
use App\Services\Documents\CsvWriter;
use App\Services\Documents\PdfRenderer;
use App\Services\Documents\XlsxWriter;
use App\Support\Audit;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * A stocktake on paper or in a spreadsheet: the results (what was counted and
 * the variance), or a blank count sheet to hand to counters. Lines run in bin
 * walk order, like the pick screens. Costs are per base unit: what each variance
 * movement was posted at, else the product's avg_cost today (lines still being
 * counted, no variance, or posted before costs were saved on variances).
 */
class StocktakeExport
{
    public const RESULTS = 'results';

    public const COUNT_SHEET = 'count-sheet';

    /** What each export comes as. */
    public const FORMATS = [self::RESULTS => ['xlsx', 'csv'], self::COUNT_SHEET => ['xlsx', 'pdf']];

    private const TYPES = [
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'csv' => 'text/csv; charset=UTF-8',
        'pdf' => 'application/pdf',
    ];

    public function __construct(private PdfRenderer $pdf) {}

    /**
     * @return array{filename: string, type: string, body: string}
     */
    public function export(Stocktake $stocktake, string $kind, string $format, bool $withCosts): array
    {
        $stocktake->load(['scope.parent.parent', 'creator:id,name', 'poster:id,name']);
        $lines = $this->lines($stocktake);
        $withCosts = $withCosts && $kind === self::RESULTS;

        $body = match ($kind) {
            self::RESULTS => $this->results($stocktake, $lines, $format, $withCosts),
            self::COUNT_SHEET => $this->countSheet($stocktake, $lines, $format),
        };

        Audit::record('stocktake_exported', $stocktake, meta: ['export' => $kind, 'format' => $format, 'costs' => $withCosts, 'lines' => $lines->count()]);

        return [
            'filename' => "{$stocktake->number}-{$kind}-".now(config('bwms.business_timezone'))->format('Y-m-d').".{$format}",
            'type' => self::TYPES[$format],
            'body' => $body,
        ];
    }

    /** @return Collection<int, StocktakeLine> */
    public function lines(Stocktake $stocktake): Collection
    {
        return $stocktake->lines()
            ->with(['product.units', 'product.range', 'location.parent.parent', 'batch', 'counter:id,name'])
            ->get()
            ->sortBy(fn (StocktakeLine $l) => [
                $l->location->walk_seq,
                $l->location->code,
                $l->product->name,
                $l->batch?->best_before?->format('Y-m-d') ?? '9999-12-31',
                $l->id,
            ])->values();
    }

    /** "3 Carton + 4 Each": the largest packs first, then base units. */
    public static function packs(int $base, Product $product): string
    {
        $parts = [];
        $left = abs($base);
        foreach ($product->units->where('base_qty', '>', 1)->sortByDesc('base_qty') as $unit) {
            if ($n = intdiv($left, $unit->base_qty)) {
                $parts[] = "{$n} {$unit->name}";
                $left -= $n * $unit->base_qty;
            }
        }
        if ($left > 0 || $parts === []) {
            $parts[] = "{$left} ".self::baseName($product);
        }

        return implode(' + ', $parts);
    }

    private function results(Stocktake $stocktake, Collection $lines, string $format, bool $withCosts): string
    {
        $columns = ['Bin', 'SKU', 'Product', 'Range', 'Batch lot', 'Best-before', 'Snapshot (base)', 'Counted (base)', 'Counted (packs)',
            'Variance (base)', 'Variance (packs)', 'Unit cost', 'Variance $ at cost', 'Counted by', 'Counted at', 'Note'];
        $rows = [];
        $netUnits = 0;
        $netCost = 0.0;
        $postedCosts = StockMovement::where('source_type', 'stocktake')->where('source_id', $stocktake->id)
            ->whereNotNull('unit_cost')->pluck('unit_cost', 'idempotency_key');

        foreach ($lines as $l) {
            $variance = $this->variance($stocktake, $l);
            $cost = (float) ($postedCosts["stk:{$stocktake->id}:line:{$l->id}"] ?? $l->product->avg_cost);
            $value = $variance === null ? null : round($variance * $cost, 2);
            $netUnits += $variance ?? 0;
            $netCost += $value ?? 0;
            $rows[] = [
                $l->location->path(), $l->product->sku, $l->product->name, $l->product->range?->name,
                $l->batch?->lot_code, $l->batch?->best_before?->format('Y-m-d'),
                $l->snapshot_qty, $l->counted_qty, $l->counted_qty === null ? null : self::packs($l->counted_qty, $l->product),
                $variance, $this->variancePacks($variance, $l->product),
                $cost, $value,
                $l->counter?->name, self::when($l->counted_at), $this->note($l),
            ];
        }

        $uncounted = $lines->whereNull('counted_qty')->count();
        $totals = ['Totals', "{$lines->count()} lines", "{$uncounted} uncounted", null, null, null, null, null, null, $netUnits, null, null, round($netCost, 2), null, null, null];

        // Staff get the same export without the two cost columns.
        $keep = $withCosts ? array_keys($columns) : array_values(array_diff(array_keys($columns), [11, 12]));
        $pick = fn (array $row) => array_map(fn ($i) => $row[$i], $keep);

        $header = $this->header($stocktake);

        if ($format === 'csv') {
            return CsvWriter::toString([...$header, [], $pick($columns), ...array_map($pick, $rows), $pick($totals)]);
        }

        $xlsx = (new XlsxWriter($stocktake->number))->landscape()
            ->widths($pick([22, 14, 34, 16, 14, 12, 10, 10, 20, 10, 24, 10, 12, 16, 16, 30]))
            ->footer("&L{$stocktake->number} results&RPage &P of &N");
        if ($withCosts) {
            $xlsx->costColumns([11])->moneyColumns([12]);
        }
        foreach ($header as $h) {
            $xlsx->row($h, true);
        }
        $xlsx->row([])->headerRow($pick($columns));
        foreach ($rows as $row) {
            $xlsx->row($pick($row));
        }

        return $xlsx->row($pick($totals), true)->toString();
    }

    private function countSheet(Stocktake $stocktake, Collection $lines, string $format): string
    {
        $rows = $lines->map(fn (StocktakeLine $l) => [
            'bin' => $l->location->path(),
            'sku' => $l->product->sku,
            'name' => $l->product->name,
            'range' => $l->product->range?->name,
            'lot' => $l->batch?->lot_code,
            'best_before' => $l->batch?->best_before?->format('Y-m-d'),
            'pack' => self::packLabel($l->product),
            'base_unit' => self::baseName($l->product),
        ]);

        if ($format === 'pdf') {
            return $this->pdf->render('pdf.stocktake-count-sheet', [
                'stocktake' => $stocktake,
                'header' => $this->header($stocktake),
                'rows' => $rows,
            ], "{$stocktake->number} count sheet", ['format' => 'A4-L', 'margin_bottom' => 24, 'margin_footer' => 6]);
        }

        $xlsx = (new XlsxWriter($stocktake->number))->landscape()
            ->widths([22, 14, 34, 16, 14, 12, 16, 14, 14, 30])
            ->boxColumns([7, 8, 9])
            ->footer('&LCounted by: ____________________   Signature: ____________________   Date: ______________&RPage &P of &N');
        foreach ($this->header($stocktake) as $h) {
            $xlsx->row($h, true);
        }
        $xlsx->row([])->headerRow(['Bin', 'SKU', 'Product', 'Range', 'Batch lot', 'Best-before', 'Pack', 'Counted (packs)', 'Counted (each)', 'Note']);
        foreach ($rows as $r) {
            $xlsx->row([$r['bin'], $r['sku'], $r['name'], $r['range'], $r['lot'], $r['best_before'], $r['pack']]);
        }

        return $xlsx->toString();
    }

    /** @return list<list<string>> */
    private function header(Stocktake $stocktake): array
    {
        return array_values(array_filter([
            ['Stocktake', $stocktake->number],
            ['Site / zone', $stocktake->scope->path()],
            ['Status', ucfirst($stocktake->status)],
            // created_at, not snapshot_at: on MariaDB with explicit_defaults_for_timestamp off, snapshot_at
            // (the table's first TIMESTAMP) is rewritten on every update, so posting moves it. Both are set together.
            ['Started', trim($stocktake->creator->name.', '.self::when($stocktake->created_at))],
            ['Posted', $stocktake->posted_at ? trim(($stocktake->poster?->name ?? '').', '.self::when($stocktake->posted_at), ', ') : '—'],
            $stocktake->note ? ['Note', $stocktake->note] : null,
        ]));
    }

    /** Posted: what went to the ledger. Otherwise: counted − system at the count. */
    private function variance(Stocktake $stocktake, StocktakeLine $line): ?int
    {
        return $stocktake->status === Stocktake::STATUS_POSTED && $line->posted_variance !== null ? $line->posted_variance : $line->variance();
    }

    /** In words, not a sign: a leading + or - would be read as a formula by a spreadsheet. */
    private function variancePacks(?int $variance, Product $product): ?string
    {
        return match (true) {
            $variance === null => null,
            $variance === 0 => 'none',
            default => self::packs($variance, $product).($variance < 0 ? ' short' : ' over'),
        };
    }

    private function note(StocktakeLine $line): ?string
    {
        $notes = [];
        // The snapshot only holds bins with stock, so a 0 there was added during the count.
        if ($line->snapshot_qty === 0) {
            $notes[] = 'Found during count';
        }
        if ($line->counted_qty !== null && $line->system_qty_at_count !== null && $line->system_qty_at_count !== $line->snapshot_qty) {
            $notes[] = "Stock moved during the count: system had {$line->system_qty_at_count} when counted";
        }
        if ($line->count_note) {
            $notes[] = ucfirst($line->count_note);
        }

        return $notes ? implode('. ', $notes) : null;
    }

    private static function packLabel(Product $product): string
    {
        $pack = $product->units->where('base_qty', '>', 1)->sortByDesc('base_qty')->first();

        return $pack ? "{$pack->name} of {$pack->base_qty}" : self::baseName($product);
    }

    private static function baseName(Product $product): string
    {
        return $product->units->firstWhere('base_qty', 1)?->name ?? $product->base_unit;
    }

    private static function when(?CarbonInterface $at): ?string
    {
        return $at?->copy()->setTimezone(config('bwms.business_timezone'))->format('Y-m-d H:i');
    }
}
