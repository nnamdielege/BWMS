<?php

namespace App\Services\Catalogue;

use App\Models\ChannelListing;
use App\Models\OrderLine;
use App\Models\Product;
use App\Models\PurchaseOrderLine;
use App\Models\StockMovement;
use App\Models\StocktakeLine;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Pre-stocktake pack setup (D-1). Migrated products start with one "Unit" pack
 * (base qty 1) that stands for the Ordermentum sell unit, often a carton. This
 * sets the real base unit and packs before any stock exists.
 *
 * CSV: sku, base_unit, pack_name, qty_per_pack[, legacy_unit]. One row per pack.
 * `legacy_unit=yes` marks which pack the old "Unit" was (the Ordermentum listing
 * and legacy PO quantities); with a single row per SKU, that row is it.
 *
 * When the old Unit becomes a pack of n base units, everything recorded in it
 * moves with it so its meaning is unchanged: the channel listing and barcode
 * move to that pack, legacy PO lines are re-pointed (base quantity x n), the
 * per-base cost is divided by n and reorder figures multiplied by n. The
 * default sell price is per default sale pack, which becomes that pack, so it
 * stays as is.
 *
 * Refused once a product has stock movements, orders, stocktake lines or
 * non-legacy PO lines: pack sizes are immutable once quantities exist.
 */
class PackSetupService
{
    /** Products still on the single default pack created by the legacy migration. */
    public static function defaultPackProducts(): Builder
    {
        return Product::query()->where('base_unit', 'unit')->where('is_stock_tracked', true)
            ->whereHas('units', fn ($q) => $q->where('name', 'Unit')->where('base_qty', 1))
            ->has('units', '=', 1);
    }

    /**
     * @return array{plans: list<array<string, mixed>>, errors: list<string>}
     */
    public function preview(string $path): array
    {
        $handle = fopen($path, 'r');
        $header = array_map(fn ($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), fgetcsv($handle) ?: []);
        foreach (['sku', 'base_unit', 'pack_name', 'qty_per_pack'] as $required) {
            if (! in_array($required, $header, true)) {
                fclose($handle);

                return ['plans' => [], 'errors' => ['The header must include sku, base_unit, pack_name, qty_per_pack (optional: legacy_unit).']];
            }
        }

        $bySku = [];
        $errors = [];
        $line = 1;
        while (($cells = fgetcsv($handle)) !== false) {
            $line++;
            if (trim(implode('', array_map('strval', $cells))) === '') {
                continue;
            }
            $r = array_combine($header, array_pad(array_slice($cells, 0, count($header)), count($header), null));
            $sku = trim((string) $r['sku']);
            $qty = trim((string) $r['qty_per_pack']);
            if ($sku === '' || trim((string) $r['base_unit']) === '' || trim((string) $r['pack_name']) === '') {
                $errors[] = "line {$line}: sku, base_unit and pack_name are required.";

                continue;
            }
            if (! preg_match('/^[1-9]\d{0,5}$/', $qty)) {
                $errors[] = "line {$line}: qty_per_pack must be a whole number from 1.";

                continue;
            }
            $bySku[$sku][] = [
                'line' => $line,
                'base_unit' => mb_strtolower(trim((string) $r['base_unit'])),
                'pack_name' => trim((string) $r['pack_name']),
                'qty' => (int) $qty,
                'legacy' => in_array(strtolower(trim((string) ($r['legacy_unit'] ?? ''))), ['yes', 'y', '1', 'true'], true),
            ];
        }
        fclose($handle);

        $plans = [];
        foreach ($bySku as $sku => $rows) {
            $plan = $this->plan($sku, $rows);
            is_string($plan) ? $errors[] = $plan : $plans[] = $plan;
        }

        return ['plans' => $plans, 'errors' => $errors];
    }

    /** @return array<string, mixed>|string a plan, or an error message */
    private function plan(string $sku, array $rows): array|string
    {
        $where = "{$sku} (line ".implode(', ', array_column($rows, 'line')).')';
        $product = Product::where('sku', $sku)->with('units')->first();
        if (! $product) {
            return "{$where}: unknown SKU.";
        }
        if (count(array_unique(array_column($rows, 'base_unit'))) > 1) {
            return "{$where}: base_unit must be the same on every row of a product.";
        }
        $qtys = array_column($rows, 'qty');
        $names = array_map('mb_strtolower', array_column($rows, 'pack_name'));
        if (count(array_unique($qtys)) !== count($qtys) || count(array_unique($names)) !== count($names)) {
            return "{$where}: each pack needs its own name and quantity.";
        }
        if ($blocker = $this->blocker($product)) {
            return "{$where}: {$blocker}";
        }
        $legacyRows = array_values(array_filter($rows, fn ($r) => $r['legacy']));
        if (count($legacyRows) > 1 || (count($rows) > 1 && $legacyRows === [])) {
            return "{$where}: mark exactly one row legacy_unit=yes (the pack the old \"Unit\" / Ordermentum item was).";
        }
        $legacy = $legacyRows[0] ?? $rows[0];
        $base = $product->units->firstWhere('base_qty', 1);
        if (! $base || $product->units->count() > 1) {
            return "{$where}: already has more than one pack; change it on the product page instead.";
        }

        $baseRow = collect($rows)->firstWhere('qty', 1);
        $n = $legacy['qty'];

        return [
            'product_id' => $product->id,
            'sku' => $product->sku,
            'name' => $product->name,
            'base_unit_before' => $product->base_unit,
            'base_unit' => $rows[0]['base_unit'],
            'base_pack_name' => $baseRow['pack_name'] ?? mb_convert_case($rows[0]['base_unit'], MB_CASE_TITLE),
            'packs' => array_values(array_map(fn ($r) => ['name' => $r['pack_name'], 'qty' => $r['qty']], array_filter($rows, fn ($r) => $r['qty'] > 1))),
            'legacy_pack' => $n > 1 ? $legacy['pack_name'] : null,
            'factor' => $n,
            'avg_cost_before' => (string) $product->avg_cost,
            'avg_cost_after' => bcdiv((string) $product->avg_cost, (string) $n, 4),
            'listings' => ChannelListing::where('product_unit_id', $base->id)->count(),
            'po_lines' => PurchaseOrderLine::where('product_unit_id', $base->id)->count(),
        ];
    }

    private function blocker(Product $product): ?string
    {
        return match (true) {
            StockMovement::where('product_id', $product->id)->exists() => 'already has stock movements; packs are fixed once stock exists.',
            OrderLine::where('product_id', $product->id)->exists() => 'already on sales orders.',
            StocktakeLine::where('product_id', $product->id)->exists() => 'already on a stocktake.',
            PurchaseOrderLine::where('product_id', $product->id)->whereHas('purchaseOrder', fn ($q) => $q->whereNull('legacy_id'))->exists() => 'already on a non-legacy purchase order.',
            default => null,
        };
    }

    /** @param  list<array<string, mixed>>  $plans */
    public function apply(array $plans, User $actor): int
    {
        return DB::transaction(function () use ($plans, $actor) {
            $done = 0;
            foreach ($plans as $plan) {
                $product = Product::lockForUpdate()->with('units')->findOrFail($plan['product_id']);
                if ($this->blocker($product) || $product->units->count() !== 1) {
                    continue; // changed since the preview; the next preview will explain
                }
                $this->applyOne($product, $plan, $actor);
                $done++;
            }

            return $done;
        });
    }

    private function applyOne(Product $product, array $plan, User $actor): void
    {
        $before = $product->only(['base_unit', 'avg_cost', 'reorder_point', 'reorder_qty']);
        $base = $product->units->firstWhere('base_qty', 1);
        $n = (int) $plan['factor'];

        $base->forceFill(['name' => $plan['base_pack_name'], 'is_base' => true])->save();
        $legacyPack = null;
        foreach ($plan['packs'] as $pack) {
            $unit = $product->units()->create(['name' => $pack['name'], 'base_qty' => $pack['qty'], 'purchasable' => true, 'sellable' => true]);
            if ($n > 1 && $pack['qty'] === $n) {
                $legacyPack = $unit;
            }
        }

        if ($legacyPack) {
            ChannelListing::where('product_unit_id', $base->id)->update(['product_unit_id' => $legacyPack->id]);
            PurchaseOrderLine::where('product_unit_id', $base->id)->update([
                'product_unit_id' => $legacyPack->id,
                'base_qty_per_unit' => $n,
                'qty_ordered_base' => DB::raw("qty_ordered * {$n}"),
            ]);
            $base->barcodes()->update(['product_unit_id' => $legacyPack->id]);
            $base->forceFill(['default_purchase' => false, 'default_sale' => false])->save();
            $legacyPack->forceFill(['default_purchase' => true, 'default_sale' => true])->save();
            $product->forceFill([
                'avg_cost' => bcdiv((string) $product->avg_cost, (string) $n, 4),
                'reorder_point' => $product->reorder_point !== null ? $product->reorder_point * $n : null,
                'reorder_qty' => $product->reorder_qty !== null ? $product->reorder_qty * $n : null,
            ]);
        }
        $product->forceFill(['base_unit' => $plan['base_unit']])->save();

        Audit::record('pack_setup_applied', $product, $before, $product->only(['base_unit', 'avg_cost', 'reorder_point', 'reorder_qty']), [
            'packs' => $plan['packs'], 'legacy_pack' => $plan['legacy_pack'], 'factor' => $n,
        ], $actor->id);
    }
}
