<?php

namespace App\Console\Commands;

use App\Enums\MovementType;
use App\Jobs\PushPortalStock;
use App\Models\ChannelListing;
use App\Models\ChannelUnmappedVariant;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Stocktake;
use App\Models\StocktakeLine;
use App\Support\Audit;
use App\Support\PackQty;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Bulk portal product mapping (spec B4.3 step 6): CSV portal_product_id, sku[, pack_qty].
 * pack_qty picks the BWMS pack the portal sells (default: the product's default
 * sale pack). Existing mappings are left alone; listings start with sync off.
 * Single products can also be mapped from the sync screen.
 *
 * --update re-points existing listings instead: pack_qty is required, and the
 * product must already have a sellable pack of that size (none is created).
 * portal_product_id may be left blank when the SKU has one portal listing. The
 * report shows old → new pack, and how the product's stock was entered, so a
 * count taken in the wrong unit can be spotted before the portal sees it.
 */
class MapPortalProducts extends Command
{
    protected $signature = 'channels:portal-map {file : CSV with portal_product_id, sku[, pack_qty]} {--update : Re-point existing listings to the pack of pack_qty} {--dry-run : Report only}';

    protected $description = 'Map portal product IDs to BWMS products and packs from a CSV';

    public function handle(): int
    {
        $rows = $this->rows();
        if ($rows === null) {
            return self::FAILURE;
        }

        return $this->option('update') ? $this->update($rows) : $this->create($rows);
    }

    /** @return array<int, array{portal_id: string, sku: string, pack_qty: string}>|null keyed by file line */
    private function rows(): ?array
    {
        $handle = @fopen((string) $this->argument('file'), 'r');
        if (! $handle) {
            $this->error('Cannot read the file.');

            return null;
        }
        $header = array_map(fn ($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), fgetcsv($handle) ?: []);
        if (! in_array('portal_product_id', $header, true) || ! in_array('sku', $header, true)) {
            $this->error('The header must include portal_product_id and sku (optional: pack_qty).');
            fclose($handle);

            return null;
        }

        $rows = [];
        $line = 1;
        while (($cells = fgetcsv($handle)) !== false) {
            $line++;
            $r = array_combine($header, array_pad(array_slice($cells, 0, count($header)), count($header), null));
            $row = [
                'portal_id' => trim((string) $r['portal_product_id']),
                'sku' => strtoupper(trim((string) $r['sku'])),
                'pack_qty' => trim((string) ($r['pack_qty'] ?? '')),
            ];
            if ($row['portal_id'] !== '' || $row['sku'] !== '') {
                $rows[$line] = $row;
            }
        }
        fclose($handle);

        return $rows;
    }

    private function create(array $rows): int
    {
        $counts = ['created' => 0, 'existing' => 0, 'errors' => 0];
        foreach ($rows as $line => $r) {
            $error = $this->mapOne($r['portal_id'], $r['sku'], $r['pack_qty'], $counts);
            if ($error) {
                $counts['errors']++;
                $this->line("  line {$line}: {$error}");
            }
        }

        $this->table(['Created', 'Already mapped', 'Errors'], [[$counts['created'], $counts['existing'], $counts['errors']]]);
        $this->option('dry-run') ? $this->warn('Dry run: nothing was saved.') : $this->info('Done. Enable portal listings on the sync screen at cutover.');

        return $counts['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function mapOne(string $portalId, string $sku, string $packQty, array &$counts): ?string
    {
        if (! ctype_digit($portalId)) {
            return "portal_product_id must be a number (got \"{$portalId}\").";
        }
        if (ChannelListing::where('channel', 'portal')->where('external_variant_id', $portalId)->exists()) {
            $counts['existing']++;

            return null;
        }
        $product = Product::where('sku', $sku)->with('units')->first();
        if (! $product) {
            return "unknown SKU {$sku}.";
        }
        $unit = $packQty !== ''
            ? $product->units->firstWhere('base_qty', (int) $packQty)
            : ($product->units->firstWhere('default_sale', true) ?? $product->units->firstWhere('base_qty', 1));
        if (! $unit || ! $unit->sellable) {
            return "{$sku}: no sellable pack".($packQty !== '' ? " of {$packQty}" : '').'.';
        }
        if (ChannelListing::where('channel', 'portal')->where('product_id', $product->id)->where('product_unit_id', $unit->id)->exists()) {
            return "{$sku} ({$unit->name}) is already mapped to another portal product.";
        }

        if (! $this->option('dry-run')) {
            ChannelListing::create([
                'channel' => 'portal', 'product_id' => $product->id, 'product_unit_id' => $unit->id,
                'external_product_id' => $portalId, 'external_variant_id' => $portalId, 'external_sku' => $sku, 'sync_enabled' => false,
            ]);
            ChannelUnmappedVariant::where('channel', 'portal')->where('external_variant_id', $portalId)->update(['resolved_at' => now()]);
            $product->addSellChannel('portal');
        }
        $counts['created']++;

        return null;
    }

    private function update(array $rows): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $report = [];
        $stock = [];
        $counts = ['changed' => 0, 'unchanged' => 0, 'refused' => 0];
        foreach ($rows as $line => $r) {
            [$listing, $unit, $refusal] = $this->repoint($r);
            $old = $listing?->unit ? self::pack($listing->unit) : '—';
            $result = match (true) {
                $refusal !== null => "REFUSED: {$refusal}",
                (int) $listing->product_unit_id === (int) $unit->id => 'unchanged',
                $dryRun => 'would change',
                default => 'changed',
            };
            $counts[$refusal !== null ? 'refused' : ($result === 'unchanged' ? 'unchanged' : 'changed')]++;
            $report[] = [$line, $listing?->external_variant_id ?? $r['portal_id'], $r['sku'], $old, $unit ? self::pack($unit) : "{$r['pack_qty']} (none)", $result];

            if ($refusal === null && $result !== 'unchanged' && ! $dryRun) {
                $this->save($listing, $unit);
            }
            if ($product = Product::where('sku', $r['sku'])->with('units')->first()) {
                $stock[$product->sku] ??= $this->stockEntry($product, (int) $r['pack_qty']);
            }
        }

        $this->table(['Line', 'Portal ID', 'SKU', 'Old pack', 'New pack', 'Result'], $report);
        if ($stock !== []) {
            $this->newLine();
            $this->info('How each product\'s stock was entered (quantities are base units):');
            $this->table(['SKU', 'Base unit', 'Pack added', 'On hand', 'Counts entered', 'Reading'], array_values($stock));
        }
        $this->line(sprintf('%d %s, %d unchanged, %d refused.', $counts['changed'], $dryRun ? 'would change' : 'changed', $counts['unchanged'], $counts['refused']));
        if ($dryRun) {
            $this->warn('Dry run: nothing was saved.');
        }

        return $counts['refused'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @return array{0: ?ChannelListing, 1: ?ProductUnit, 2: ?string} the listing, the pack it should sell, or why not */
    private function repoint(array $r): array
    {
        $product = Product::where('sku', $r['sku'])->with('units')->first();
        if (! $product) {
            return [null, null, "unknown SKU {$r['sku']}."];
        }
        $listings = ChannelListing::where('channel', 'portal')->with('unit')
            ->when($r['portal_id'] !== '', fn ($q) => $q->where('external_variant_id', $r['portal_id']), fn ($q) => $q->where('product_id', $product->id))
            ->get();
        $listing = $listings->count() === 1 ? $listings->first() : null;
        $unit = ctype_digit($r['pack_qty']) ? $product->units->firstWhere('base_qty', (int) $r['pack_qty']) : null;
        $packs = $product->units->sortBy('base_qty')->map(fn (ProductUnit $u) => self::pack($u))->implode(', ');

        $refusal = match (true) {
            ! ctype_digit($r['pack_qty']) || (int) $r['pack_qty'] < 1 => 'pack_qty is required with --update and must be a whole number.',
            $r['portal_id'] !== '' && ! ctype_digit($r['portal_id']) => "portal_product_id must be a number (got \"{$r['portal_id']}\").",
            $listings->isEmpty() => $r['portal_id'] !== '' ? "portal product {$r['portal_id']} isn't mapped. Map it without --update." : "{$r['sku']} has no portal listing. Map it without --update.",
            $listing === null => "{$r['sku']} has {$listings->count()} portal listings: give the portal_product_id.",
            (int) $listing->product_id !== (int) $product->id => "portal product {$listing->external_variant_id} is mapped to another product, not {$r['sku']}.",
            $unit === null => "{$r['sku']} has no pack of {$r['pack_qty']} (it has {$packs}). Add the pack on the product page first.",
            ! $unit->sellable => "{$r['sku']}'s {$unit->name} ×{$unit->base_qty} isn't sellable. Make it sellable on the product page first.",
            ChannelListing::where('channel', 'portal')->where('product_id', $product->id)->where('product_unit_id', $unit->id)->whereKeyNot($listing->id)->exists() => "{$r['sku']}'s {$unit->name} ×{$unit->base_qty} is already mapped to another portal product.",
            default => null,
        };

        return [$listing, $unit, $refusal];
    }

    private function save(ChannelListing $listing, ProductUnit $unit): void
    {
        $old = ['product_unit_id' => $listing->product_unit_id, 'pack' => $listing->unit ? self::pack($listing->unit) : null];
        $listing->forceFill(['product_unit_id' => $unit->id])->save();
        Audit::record('portal_listing_repointed', $listing, old: $old, new: ['product_unit_id' => $unit->id, 'pack' => self::pack($unit)], meta: ['sku' => $listing->product->sku, 'via' => 'channels:portal-map --update']);
        // The portal's figure is in packs, so it changes with the pack.
        if ($listing->sync_enabled) {
            PushPortalStock::request([$listing->product_id], force: true);
        }
    }

    /**
     * Facts to tell whether stock was entered in base units or in packs. On-hand
     * is always base units: if counts were typed as cartons, they show up as small
     * numbers that aren't multiples of the pack, and need converting.
     *
     * @return list<string>
     */
    private function stockEntry(Product $product, int $packQty): array
    {
        $pack = $packQty > 1 ? $product->units->firstWhere('base_qty', $packQty) : null;
        $onHand = (int) StockBalance::where('product_id', $product->id)->sum('on_hand');
        $firstStock = StockMovement::where('product_id', $product->id)->min('occurred_at');

        // Opening balances and posted stocktake counts: the figures someone typed in.
        $entered = StockMovement::where('product_id', $product->id)->where('type', MovementType::OpeningBalance)->orderBy('id')
            ->get(['qty', 'occurred_at'])->map(fn ($m) => ['qty' => (int) $m->qty, 'what' => 'opening', 'at' => $m->occurred_at]);
        $counted = StocktakeLine::where('product_id', $product->id)->whereNotNull('counted_qty')
            ->whereHas('stocktake', fn ($q) => $q->where('status', Stocktake::STATUS_POSTED))->orderBy('id')
            ->get(['counted_qty', 'counted_at'])->map(fn ($l) => ['qty' => (int) $l->counted_qty, 'what' => 'stocktake', 'at' => $l->counted_at]);
        $all = $entered->concat($counted)->filter(fn ($e) => $e['qty'] !== 0)->values();

        $packAdded = $pack ? $pack->created_at?->setTimezone(config('bwms.business_timezone'))->format('Y-m-d') : 'no pack';
        if ($pack && $firstStock && $pack->created_at?->gt(Carbon::parse($firstStock))) {
            $packAdded .= ' (after stock was entered)';
        }
        $list = $all->map(fn ($e) => "{$e['qty']} ({$e['what']} ".$e['at']?->setTimezone(config('bwms.business_timezone'))->format('Y-m-d').')')->implode(', ');

        $reading = match (true) {
            $packQty <= 1 => 'pack of 1: nothing to convert',
            $all->isEmpty() => $onHand === 0 ? 'no stock entered yet' : 'no counts entered; check the receipts',
            $all->every(fn ($e) => $e['qty'] % $packQty === 0) => "all multiples of {$packQty}: looks entered in base units",
            $all->every(fn ($e) => $e['qty'] < $packQty) => "all under {$packQty}: may be cartons typed as {$product->base_unit}; check the shelf",
            default => 'mixed: check against the shelf',
        };

        return [
            $product->sku,
            $product->base_unit,
            $packAdded,
            $onHand.($pack ? ' = '.PackQty::label($onHand, $packQty, $pack->name, $product->base_unit) : ''),
            $list === '' ? '—' : $list,
            $reading,
        ];
    }

    private static function pack(ProductUnit $unit): string
    {
        return "{$unit->name} ×{$unit->base_qty}";
    }
}
