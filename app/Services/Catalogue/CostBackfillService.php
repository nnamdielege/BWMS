<?php

namespace App\Services\Catalogue;

use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * D-11 cost back-fill. Costs are stored per BASE unit (products.avg_cost);
 * users may enter them per pack ("cost of a carton of 12") and we divide.
 *
 * CSV format (header row required, case-insensitive): sku, cost[, pack]
 *   pack = pack name ("Carton") or quantity ("12"); omitted = base unit.
 */
class CostBackfillService
{
    public const MAX_ROWS = 5000;

    /**
     * Parse and validate a CSV without changing anything.
     *
     * @return array{rows: list<array<string, mixed>>, valid: int, errors: int}
     */
    public function preview(string $path): array
    {
        $handle = fopen($path, 'r');
        $header = array_map(fn ($h) => Str::lower(trim((string) $h, " \t\n\r\0\x0B\u{FEFF}")), fgetcsv($handle) ?: []);
        $col = array_flip($header);

        if (! isset($col['sku'], $col['cost'])) {
            fclose($handle);

            return ['rows' => [['line' => 1, 'error' => 'Header must include "sku" and "cost" columns.']], 'valid' => 0, 'errors' => 1];
        }

        $raw = [];
        $line = 1;
        while (($r = fgetcsv($handle)) !== false && count($raw) < self::MAX_ROWS) {
            $line++;
            if ($r === [null] || implode('', $r) === '') {
                continue;
            }
            $raw[] = [
                'line' => $line,
                'sku' => strtoupper(trim((string) ($r[$col['sku']] ?? ''))),
                'cost' => trim((string) ($r[$col['cost']] ?? '')),
                'pack' => isset($col['pack']) ? trim((string) ($r[$col['pack']] ?? '')) : '',
            ];
        }
        fclose($handle);

        $products = Product::with('units')->whereIn('sku', array_column($raw, 'sku'))->get()->keyBy('sku');
        $seen = [];
        $rows = [];

        foreach ($raw as $r) {
            $row = $r + ['product_id' => null, 'old_cost' => null, 'new_cost' => null, 'error' => null];
            $product = $products[$r['sku']] ?? null;

            $row['error'] = match (true) {
                $r['sku'] === '' => 'Missing SKU.',
                ! $product => 'Unknown SKU.',
                isset($seen[$r['sku']]) => "Duplicate of line {$seen[$r['sku']]}.",
                ! is_numeric($r['cost']) || (float) $r['cost'] < 0 || (float) $r['cost'] > 1_000_000 => 'Cost must be a number between 0 and 1,000,000.',
                default => null,
            };

            if (! $row['error']) {
                $unit = $this->resolveUnit($product, $r['pack']);
                if (! $unit) {
                    $row['error'] = "Pack \"{$r['pack']}\" not found for this product.";
                } else {
                    $row['product_id'] = $product->id;
                    $row['old_cost'] = (string) $product->avg_cost;
                    $row['new_cost'] = $this->perBase((string) $r['cost'], $unit);
                    $row['pack_label'] = $unit->name.($unit->base_qty > 1 ? " ×{$unit->base_qty}" : '');
                }
            }

            $seen[$r['sku']] ??= $r['line'];
            $rows[] = $row;
        }

        $errors = count(array_filter($rows, fn ($r) => $r['error'] !== null));

        return ['rows' => $rows, 'valid' => count($rows) - $errors, 'errors' => $errors];
    }

    /**
     * Apply the valid rows of a preview atomically. Rows with errors are skipped.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public function apply(array $rows, User $actor, string $source): int
    {
        $valid = array_values(array_filter($rows, fn ($r) => $r['error'] === null && $r['product_id']));

        return DB::transaction(function () use ($valid, $actor, $source) {
            $changed = 0;
            foreach ($valid as $r) {
                $product = Product::lockForUpdate()->find($r['product_id']);
                if ($product && bccomp((string) $product->avg_cost, (string) $r['new_cost'], 4) !== 0) {
                    $this->setCost($product, (string) $r['new_cost'], $actor, $source);
                    $changed++;
                }
            }

            return $changed;
        });
    }

    /** Set one product's cost per base unit (audited via the model + an explicit event). */
    public function setCost(Product $product, string $costPerBase, User $actor, string $source): void
    {
        $old = (string) $product->avg_cost;
        $product->forceFill(['avg_cost' => $costPerBase])->save();
        Audit::record('cost_set', $product, ['avg_cost' => $old], ['avg_cost' => $costPerBase], ['source' => $source], $actor->id);
    }

    public function perBase(string $packCost, ProductUnit $unit): string
    {
        return bcdiv($packCost, (string) $unit->base_qty, 4);
    }

    public function resolveUnit(Product $product, string $pack): ?ProductUnit
    {
        if ($pack === '') {
            return $product->units->firstWhere('base_qty', 1);
        }

        return ctype_digit($pack)
            ? $product->units->firstWhere('base_qty', (int) $pack)
            : $product->units->first(fn (ProductUnit $u) => Str::lower($u->name) === Str::lower($pack));
    }
}
