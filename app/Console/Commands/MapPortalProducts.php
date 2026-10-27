<?php

namespace App\Console\Commands;

use App\Models\ChannelListing;
use App\Models\ChannelUnmappedVariant;
use App\Models\Product;
use Illuminate\Console\Command;

/**
 * Bulk portal product mapping (spec B4.3 step 6): CSV portal_product_id, sku[, pack_qty].
 * pack_qty picks the BWMS pack the portal sells (default: the product's default
 * sale pack). Existing mappings are left alone; listings start with sync off.
 * Single products can also be mapped from the sync screen.
 */
class MapPortalProducts extends Command
{
    protected $signature = 'channels:portal-map {file : CSV with portal_product_id, sku[, pack_qty]} {--dry-run : Report only}';

    protected $description = 'Map portal product IDs to BWMS products and packs from a CSV';

    public function handle(): int
    {
        $handle = @fopen((string) $this->argument('file'), 'r');
        if (! $handle) {
            $this->error('Cannot read the file.');

            return self::FAILURE;
        }
        $header = array_map(fn ($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), fgetcsv($handle) ?: []);
        if (! in_array('portal_product_id', $header, true) || ! in_array('sku', $header, true)) {
            $this->error('The header must include portal_product_id and sku (optional: pack_qty).');

            return self::FAILURE;
        }

        $counts = ['created' => 0, 'existing' => 0, 'errors' => 0];
        $line = 1;
        while (($cells = fgetcsv($handle)) !== false) {
            $line++;
            $r = array_combine($header, array_pad(array_slice($cells, 0, count($header)), count($header), null));
            $portalId = trim((string) $r['portal_product_id']);
            $sku = strtoupper(trim((string) $r['sku']));
            if ($portalId === '' && $sku === '') {
                continue;
            }
            $error = $this->mapOne($portalId, $sku, trim((string) ($r['pack_qty'] ?? '')), $counts);
            if ($error) {
                $counts['errors']++;
                $this->line("  line {$line}: {$error}");
            }
        }
        fclose($handle);

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
        }
        $counts['created']++;

        return null;
    }
}
