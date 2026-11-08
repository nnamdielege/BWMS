<?php

namespace App\Services\Stock;

use App\Models\Batch;
use App\Models\Location;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\Stocktake;
use App\Models\StocktakeLine;
use App\Models\User;
use App\Services\Documents\SpreadsheetReader;
use App\Services\Stock\Exceptions\InvalidMovement;
use App\Support\Audit;
use App\Support\PackQty;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Counts typed into a count sheet or a results export and uploaded back. The
 * file is read into a preview, kept 30 minutes per user, and applying it records
 * the counts as if typed in. It never posts.
 *
 * Rows are matched on bin + SKU + lot + best-before. Rows for the same slot are
 * added together (a bin counted in parts). Counts are packs (the product's
 * largest, as on the sheet) plus each, or base units; if both are filled they
 * must agree. Any error holds the whole file back, so a count is never half in.
 */
class StocktakeUpload
{
    public const MINUTES = 30;

    public const MAX_ROWS = 5000;

    /** Header text => what it is. Other columns are ignored. */
    private const COLUMNS = [
        'bin' => 'bin', 'sku' => 'sku',
        'batch lot' => 'lot', 'lot' => 'lot', 'lot code' => 'lot',
        'best-before' => 'best_before', 'best before' => 'best_before',
        'counted (packs)' => 'packs', 'counted packs' => 'packs',
        'counted (each)' => 'each', 'counted each' => 'each',
        'counted (base)' => 'base', 'counted base' => 'base',
    ];

    public function __construct(private SpreadsheetReader $sheets, private StocktakeService $stocktakes, private StockQuery $stock) {}

    /**
     * Reads the file into a preview and keeps it for apply.
     *
     * @throws RuntimeException when the file can't be used at all
     */
    public function preview(Stocktake $stocktake, User $actor, string $path, string $extension, string $filename): array
    {
        if (! $stocktake->isOpen()) {
            throw new RuntimeException("Stocktake {$stocktake->number} is {$stocktake->status}.");
        }
        $plan = $this->plan($stocktake, $this->sheets->rows($path, $extension, self::MAX_ROWS + 20));
        $plan += [
            'token' => (string) Str::uuid(),
            'stocktake_id' => $stocktake->id,
            'filename' => $filename,
            'expires_at' => now()->addMinutes(self::MINUTES)->timestamp,
        ];
        Cache::put($this->key($actor, $plan['token']), $plan, now()->addMinutes(self::MINUTES));

        Audit::record('stocktake_counts_uploaded', $stocktake, meta: ['file' => $filename] + $this->summary($plan));

        return $plan;
    }

    public function get(User $actor, string $token): ?array
    {
        return Cache::get($this->key($actor, $token));
    }

    public function discard(User $actor, string $token): void
    {
        Cache::forget($this->key($actor, $token));
    }

    /**
     * Records the previewed counts, all or none. The preview is taken out first,
     * so a double submit finds nothing; a refused apply puts it back.
     *
     * @return array{counted: int, added: int}
     *
     * @throws InvalidMovement when the preview is gone, has errors, or the counts changed since
     */
    public function apply(Stocktake $stocktake, User $actor, string $token): array
    {
        $plan = Cache::pull($this->key($actor, $token));
        if (! $plan) {
            throw new InvalidMovement('That upload has expired or was already applied. Upload the file again.');
        }

        try {
            if ($plan['stocktake_id'] !== $stocktake->id) {
                throw new InvalidMovement('That upload is for another stocktake.');
            }
            $result = $this->write($stocktake, $actor, $plan);
        } catch (Throwable $e) {
            if ($plan['expires_at'] > now()->timestamp) {
                Cache::put($this->key($actor, $token), $plan, now()->setTimestamp($plan['expires_at']));
            }
            throw $e;
        }

        Audit::record('stocktake_counts_applied', $stocktake, meta: ['file' => $plan['filename']] + $result);

        return $result;
    }

    /** @return array{matched: int, new: int, unchanged: int, errors: int, blank: int} */
    public function summary(array $plan): array
    {
        return [
            'matched' => count(array_filter($plan['matched'], fn ($m) => ! $m['unchanged'])),
            'new' => count($plan['new']),
            'unchanged' => count(array_filter($plan['matched'], fn ($m) => $m['unchanged'])),
            'errors' => count($plan['errors']),
            'blank' => count($plan['blank']),
        ];
    }

    /** @return array{counted: int, added: int} */
    private function write(Stocktake $stocktake, User $actor, array $plan): array
    {
        if ($plan['errors'] !== []) {
            throw new InvalidMovement('Fix the errors in the file and upload it again.');
        }
        $note = "uploaded from {$plan['filename']}";

        return DB::transaction(function () use ($stocktake, $actor, $plan, $note) {
            $stocktake = Stocktake::whereKey($stocktake->id)->lockForUpdate()->firstOrFail();
            if (! $stocktake->isOpen()) {
                throw new InvalidMovement("Stocktake {$stocktake->number} is {$stocktake->status}.");
            }

            $counted = 0;
            foreach ($plan['matched'] as $m) {
                if ($m['unchanged']) {
                    continue;
                }
                $line = $stocktake->lines()->whereKey($m['line_id'])->lockForUpdate()->firstOrFail();
                if ($line->counted_qty !== $m['old']) {
                    throw new InvalidMovement("{$m['sku']} in {$m['bin']} was counted since the preview. Upload the file again.");
                }
                $this->stocktakes->recordCount($line, $m['new'], $actor, $note);
                $counted++;
            }

            foreach ($plan['new'] as $n) {
                $product = Product::findOrFail($n['product_id']);
                $batch = $n['lot'] !== null ? Batch::findOrCreateFor($product, $n['lot'], $n['best_before']) : null;
                $line = $this->stocktakes->addLine($stocktake, $product, Location::findOrFail($n['location_id']), $batch);
                if ($line->counted_qty !== null) {
                    throw new InvalidMovement("{$n['sku']} in {$n['bin']} was added and counted since the preview. Upload the file again.");
                }
                $this->stocktakes->recordCount($line, $n['new'], $actor, $note);
            }

            return ['counted' => $counted, 'added' => count($plan['new'])];
        });
    }

    /** @param  array<int, list<string>>  $rows */
    private function plan(Stocktake $stocktake, array $rows): array
    {
        $this->assertSameStocktake($stocktake, $rows);
        [$headerAt, $columns] = $this->header($rows);

        $lines = $stocktake->lines()->with(['product.units', 'location', 'batch'])->get()
            ->keyBy(fn (StocktakeLine $l) => self::slot($l->product_id, $l->location_id, (string) $l->batch_key));
        $bins = new BinLookup($this->stock->binIdsUnder($stocktake->scope));
        $products = [];

        $errors = [];
        $blank = [];
        $slots = [];
        $seen = 0;
        foreach ($rows as $i => $cells) {
            if ($i <= $headerAt) {
                continue;
            }
            $cell = fn (string $what) => isset($columns[$what]) ? self::clean($cells[$columns[$what]] ?? '') : '';
            if (implode('', array_map(fn ($c) => trim((string) $c), $cells)) === '' || strcasecmp($cell('bin'), 'Totals') === 0) {
                continue;
            }
            $rowNo = $i + 1;
            if (++$seen > self::MAX_ROWS) {
                $errors[] = ['row' => $rowNo, 'message' => 'Too many rows: upload at most '.self::MAX_ROWS.'.'];
                break;
            }

            $sku = strtoupper($cell('sku'));
            $product = $sku === '' ? null : ($products[$sku] ??= Product::with('units')->where('sku', $sku)->first() ?? false);
            $bin = $bins->find($cell('bin'));
            $qty = $this->quantity($cell, $product ? PackQty::largestPack($product)?->base_qty ?? 1 : 1);
            $isBlank = $qty['blank'];

            $problem = match (true) {
                $sku === '' => 'No SKU.',
                ! $product => "Unknown SKU {$sku}.",
                $cell('bin') === '' => 'No bin.',
                $bin === BinLookup::UNKNOWN => "Unknown bin {$cell('bin')}.",
                $bin === BinLookup::OUTSIDE => "Bin {$cell('bin')} is outside this stocktake's scope.",
                $bin === BinLookup::AMBIGUOUS => "Bin {$cell('bin')} matches more than one bin. Use the full path, as on the count sheet.",
                default => null,
            };
            // A blank row is left uncounted whatever else is on it.
            if ($isBlank) {
                $line = $problem === null ? $this->findLine($lines, $product, $bin, $cell) : null;
                $blank[] = ['row' => $rowNo, 'sku' => $sku, 'bin' => $cell('bin'), 'counted' => $line?->counted_qty === null ? null : $this->label($line->counted_qty, $line->product)];

                continue;
            }
            $problem ??= $qty['error'] ?? match (true) {
                ! $product->is_stock_tracked => "{$sku} is not stock-tracked and is not counted.",
                $product->is_batch_tracked && $cell('lot') === '' => "{$sku} is batch-tracked: fill in the lot.",
                ! $product->is_batch_tracked && $cell('lot') !== '' => "{$sku} is not batch-tracked: leave the lot empty.",
                default => null,
            };
            $bestBefore = null;
            if ($problem === null && $cell('best_before') !== '') {
                $bestBefore = self::date($cell('best_before'));
                $problem = $bestBefore === null ? "Best-before {$cell('best_before')} is not a date (use YYYY-MM-DD)." : null;
            }
            $batch = null;
            if ($problem === null && $product->is_batch_tracked) {
                [$batch, $problem] = $this->batch($product, $cell('lot'), $bestBefore);
            }
            if ($problem !== null) {
                $errors[] = ['row' => $rowNo, 'message' => $problem];

                continue;
            }

            $batchKey = $batch === null ? '0' : ($batch['id'] ?? "new:{$batch['lot']}|{$batch['best_before']}");
            $key = self::slot($product->id, $bin, (string) $batchKey);
            $slots[$key] ??= ['product' => $product, 'location_id' => $bin, 'batch' => $batch, 'bin' => $cell('bin'), 'qty' => 0, 'rows' => []];
            $slots[$key]['qty'] += $qty['base'];
            $slots[$key]['rows'][] = $rowNo;
        }

        return $this->sorted($slots, $lines, $errors, $blank);
    }

    private function sorted(array $slots, Collection $lines, array $errors, array $blank): array
    {
        $matched = [];
        $new = [];
        foreach ($slots as $key => $s) {
            $product = $s['product'];
            $line = $lines->get($key);
            [, $locationId, $batchKey] = explode('|', $key, 3);
            $system = is_numeric($batchKey)
                ? (int) StockBalance::where(['product_id' => $product->id, 'location_id' => (int) $locationId, 'batch_key' => (int) $batchKey])->value('on_hand')
                : 0;
            $common = [
                'rows' => $s['rows'], 'sku' => $product->sku, 'name' => $product->name,
                'bin' => $line?->location->path() ?? Location::find($s['location_id'])->path(),
                'new' => $s['qty'], 'new_label' => $this->label($s['qty'], $product),
                // What the variance will be if applied now: counted − system now.
                'variance' => $s['qty'] - $system,
                'variance_label' => $this->label($s['qty'] - $system, $product),
            ];
            if ($line) {
                $matched[] = $common + [
                    'line_id' => $line->id,
                    'batch' => $line->batch?->label(),
                    'old' => $line->counted_qty,
                    'old_label' => $line->counted_qty === null ? null : $this->label($line->counted_qty, $product),
                    'unchanged' => $line->counted_qty === $s['qty'],
                ];
            } else {
                $new[] = $common + [
                    'product_id' => $product->id,
                    'location_id' => $s['location_id'],
                    'lot' => $s['batch']['lot'] ?? null,
                    'best_before' => $s['batch']['best_before'] ?? null,
                    'batch' => $s['batch'] ? trim($s['batch']['lot'].($s['batch']['best_before'] ? " (BB {$s['batch']['best_before']})" : '')) : null,
                ];
            }
        }

        return ['matched' => $matched, 'new' => $new, 'errors' => $errors, 'blank' => $blank];
    }

    /**
     * Packs + each, or base. Text in the packs column (the results export's
     * "3 Carton + 4 Each") is a label, not a count, and is ignored.
     *
     * @return array{blank: bool, base?: int, error?: string}
     */
    private function quantity(callable $cell, int $per): array
    {
        $values = [];
        foreach (['packs' => 'Counted (packs)', 'each' => 'Counted (each)', 'base' => 'Counted (base)'] as $what => $title) {
            $raw = $cell($what);
            if ($what === 'packs' && $raw !== '' && ! is_numeric($raw)) {
                $raw = '';
            }
            if ($raw === '') {
                continue;
            }
            if (! preg_match('/^-?\d+(\.0+)?$/', $raw)) {
                return ['blank' => false, 'error' => "{$title} {$raw} is not a whole number."];
            }
            if ((int) $raw < 0) {
                return ['blank' => false, 'error' => "{$title} is negative ({$raw})."];
            }
            if ((int) $raw > 10000000) {
                return ['blank' => false, 'error' => "{$title} {$raw} is too big."];
            }
            $values[$what] = (int) $raw;
        }
        if ($values === []) {
            return ['blank' => true];
        }

        $fromPacks = isset($values['packs']) || isset($values['each']) ? PackQty::toBase($values, $per) : null;
        if ($fromPacks !== null && isset($values['base']) && $fromPacks !== $values['base']) {
            return ['blank' => false, 'error' => "Counted (packs) and (each) make {$fromPacks}, but Counted (base) says {$values['base']}. Fill in one or the other."];
        }

        return ['blank' => false, 'base' => $fromPacks ?? $values['base']];
    }

    /**
     * The batch a lot names: an existing one (by lot and best-before, or the
     * lot's only batch when no best-before is given), or one to create on apply.
     *
     * @return array{0: array{id?: int, lot: string, best_before: ?string}|null, 1: ?string}
     */
    private function batch(Product $product, string $lot, ?string $bestBefore): array
    {
        $found = Batch::where('product_id', $product->id)->where('lot_code', $lot)
            ->when($bestBefore !== null, fn ($q) => $q->whereDate('best_before', $bestBefore))->get();
        if ($found->count() > 1) {
            return [null, "Lot {$lot} of {$product->sku} has more than one best-before: fill in the best-before."];
        }
        $batch = $found->first();

        return [['id' => $batch?->id, 'lot' => $batch->lot_code ?? $lot, 'best_before' => $batch?->best_before?->format('Y-m-d') ?? $bestBefore], null];
    }

    /** The stocktake line a blank row names, if it can be told. */
    private function findLine(Collection $lines, Product $product, int $bin, callable $cell): ?StocktakeLine
    {
        return $lines->first(fn (StocktakeLine $l) => $l->product_id === $product->id && $l->location_id === $bin
            && strcasecmp((string) $l->batch?->lot_code, $cell('lot')) === 0);
    }

    /** A file whose header names another stocktake is refused whole. */
    private function assertSameStocktake(Stocktake $stocktake, array $rows): void
    {
        foreach (array_slice($rows, 0, 10) as $cells) {
            if (strcasecmp(trim($cells[0] ?? ''), 'Stocktake') === 0 && ($other = trim($cells[1] ?? '')) !== '' && $other !== $stocktake->number) {
                throw new RuntimeException("This file is for {$other}, not {$stocktake->number}.");
            }
        }
    }

    /** @return array{0: int, 1: array<string, int>} the header's row key, and column index by what it is */
    private function header(array $rows): array
    {
        foreach ($rows as $i => $cells) {
            $columns = [];
            foreach ($cells as $index => $text) {
                $what = self::COLUMNS[Str::lower(trim((string) $text))] ?? null;
                if ($what !== null && ! isset($columns[$what])) {
                    $columns[$what] = $index;
                }
            }
            if (isset($columns['bin'], $columns['sku'])) {
                if (! isset($columns['packs']) && ! isset($columns['each']) && ! isset($columns['base'])) {
                    throw new RuntimeException('The file has no count columns: Counted (packs), Counted (each) or Counted (base).');
                }

                return [$i, $columns];
            }
        }

        throw new RuntimeException('No header row with Bin and SKU. Upload the count sheet or results export of this stocktake.');
    }

    private function label(int $base, Product $product): string
    {
        $pack = PackQty::largestPack($product);

        return PackQty::label($base, $pack?->base_qty ?? 1, $pack?->name ?? $product->base_unit, $product->base_unit);
    }

    /** Trimmed, without the quote the CSV export puts before text that could run as a formula. */
    private static function clean(string $value): string
    {
        $value = trim($value);

        return preg_match("/^'[=+\\-@]/", $value) ? substr($value, 1) : $value;
    }

    /** YYYY-MM-DD, DD/MM/YYYY, or the day number Excel keeps when a cell was typed as a date. */
    private static function date(string $value): ?string
    {
        if (preg_match('/^\d{5}(\.0+)?$/', $value)) {
            return Carbon::create(1899, 12, 30)->addDays((int) $value)->format('Y-m-d');
        }
        $ymd = match (true) {
            (bool) preg_match('/^(\d{4})-(\d{2})-(\d{2})( 00:00(:00)?)?$/', $value, $m) => [(int) $m[1], (int) $m[2], (int) $m[3]],
            (bool) preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $value, $m) => [(int) $m[3], (int) $m[2], (int) $m[1]],
            default => null,
        };

        return $ymd && checkdate($ymd[1], $ymd[2], $ymd[0]) ? sprintf('%04d-%02d-%02d', ...$ymd) : null;
    }

    private static function slot(int $productId, int $locationId, string $batchKey): string
    {
        return "{$productId}|{$locationId}|{$batchKey}";
    }

    private function key(User $actor, string $token): string
    {
        return "stocktake-upload:{$actor->id}:{$token}";
    }
}
