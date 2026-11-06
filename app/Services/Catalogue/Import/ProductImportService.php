<?php

namespace App\Services\Catalogue\Import;

use App\Integrations\Ordermentum\OrderInbox;
use App\Models\Barcode;
use App\Models\ChannelListing;
use App\Models\ChannelUnmappedVariant;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Range;
use App\Models\User;
use App\Services\Catalogue\ProductService;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Carries out an import plan. Catalogue only: products, packs, barcodes and
 * channel listings. It never touches stock, and listings start with sync off,
 * so pushing stays a decision on the Sync screen.
 *
 * Batches of 100, each one transaction. A batch that fails stops the run: the
 * batches before it stay, and importing again picks up the rest. One audit entry
 * per product, written here instead of one per model row.
 */
class ProductImportService
{
    public const BATCH = 100;

    public function __construct(
        private ProductImportPlanner $planner,
        private ProductService $products,
        private OrderInbox $inbox,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, array<string, mixed>>  $edits  by item key
     * @param  bool  $skipUnresolved  leave "Check pack" rows out instead of refusing (the command has no preview to fill them in)
     * @return array{created: int, updated: int, mapped: int, unchanged: int, skipped: int, replayed: int, failed: ?string}
     *
     * @throws ValidationException when an included row still needs a pack
     */
    public function apply(array $items, array $edits, ?User $actor, string $source, bool $skipUnresolved = false): array
    {
        $rows = $this->planner->plan($items, $edits);
        $unresolved = array_filter($rows, fn ($r) => $r['check_pack'] && $r['included']);
        if ($unresolved !== [] && ! $skipUnresolved) {
            throw ValidationException::withMessages(['edits' => count($unresolved).' row(s) still need a pack. Fill them in or exclude them.']);
        }

        $work = [];
        foreach ($rows as $i => $row) {
            if ($this->applicable($row)) {
                $work[] = $items[$i];
            }
        }
        $shared = $this->planner->sharedConflicts($items, $edits);
        $result = ['created' => 0, 'updated' => 0, 'mapped' => 0, 'unchanged' => 0, 'skipped' => count($rows) - count($work), 'replayed' => 0, 'failed' => null];
        $newVariants = [];

        foreach (array_chunk($work, self::BATCH) as $n => $batch) {
            try {
                [$tally, $variants] = DB::transaction(function () use ($batch, $edits, $shared, $actor, $source) {
                    $tally = [];
                    $variants = [];
                    foreach ($batch as $item) {
                        $row = $this->planner->resolveNow($item, $edits[$item['key']] ?? [], $shared);
                        $event = $this->applicable($row) ? $this->carryOut($row, $actor, $source, $variants) : 'skipped';
                        $tally[$event] = ($tally[$event] ?? 0) + 1;
                    }

                    return [$tally, $variants];
                });
            } catch (Throwable $e) {
                report($e);
                $result['failed'] = 'Batch '.($n + 1).' was not saved: '.$e->getMessage();
                break;
            }
            foreach ($tally as $event => $count) {
                $result[$event] += $count;
            }
            $newVariants = [...$newVariants, ...$variants];
        }

        foreach (array_unique($newVariants) as $variantId) {
            $result['replayed'] += $this->inbox->replayHeldFor($variantId);
        }

        return $result;
    }

    private function applicable(array $row): bool
    {
        return $row['included'] && ! $row['check_pack'] && in_array($row['group'], ['new', 'update', 'mapping'], true);
    }

    /**
     * @param  list<string>  $variants  Ordermentum variants mapped here, for replaying held orders
     * @return string the tally this row counts under
     */
    private function carryOut(array $row, ?User $actor, string $source, array &$variants): string
    {
        $a = $row['actions'];
        $rangeId = isset($a['range']) ? Range::firstOrCreate(['name' => mb_substr($a['range'], 0, 255)])->id : null;

        return Audit::withoutModelEvents(function () use ($row, $a, $rangeId, $actor, $source, &$variants) {
            $meta = ['source' => $source] + array_filter(['line' => $row['line'], 'external_sku' => $row['sku']]);

            if (isset($a['create'])) {
                $product = $this->create($a, $rangeId);
                $this->addListings($product, $a['listings'], $variants);
                Audit::record('product_import_created', $product, null, $a['create'] + ['range' => $a['range'] ?? null], $meta + [
                    'pack' => "{$a['pack']['name']} ×{$a['pack']['size']}", 'listings' => array_keys($a['listings']),
                ], $actor?->id);

                return 'created';
            }

            $product = Product::with('units.barcodes')->lockForUpdate()->findOrFail($a['product_id']);
            $attributes = $a['attributes'] + ($rangeId ? ['range_id' => $rangeId] : []);
            $old = array_intersect_key($product->getAttributes(), $attributes);
            if ($attributes !== []) {
                $product->update($attributes);
            }
            if ($a['add_pack']) {
                $unit = $this->addPack($product, $a['add_pack']);
                $product->load('units.barcodes');
            }
            foreach ($a['repoint'] as $repoint) {
                ChannelListing::whereKey($repoint['listing_id'])->update(['product_unit_id' => $product->units->firstWhere('base_qty', $row['pack_size'])->id]);
            }
            if ($a['barcode']) {
                $this->addBarcode($product->units->firstWhere('base_qty', $a['barcode_pack_size']), $a['barcode']);
            }
            $this->addListings($product, $a['listings'], $variants);

            $mappingOnly = $attributes === [] && ! $a['add_pack'] && $a['repoint'] === [] && ! $a['barcode'];
            if ($mappingOnly && $a['listings'] === []) {
                return 'unchanged';
            }
            $event = $mappingOnly ? 'mapped' : 'updated';
            Audit::record("product_import_{$event}", $product, $old, $attributes, $meta + array_filter([
                'pack_added' => $a['add_pack'] ? "{$a['add_pack']['name']} ×{$a['add_pack']['size']}" : null,
                'listings' => array_keys($a['listings']), 'repointed' => array_column($a['repoint'], 'channel'), 'barcode' => $a['barcode'],
            ]), $actor?->id);

            return $event;
        });
    }

    private function create(array $a, ?int $rangeId): Product
    {
        $pack = $a['pack'];
        $sold = ['name' => $pack['name'], 'base_qty' => $pack['size'], 'purchasable' => true, 'sellable' => true, 'default_purchase' => true, 'default_sale' => true,
            'barcodes' => $a['barcode'] ? [$a['barcode']] : []];
        $units = $pack['size'] > 1
            ? [['name' => 'Each', 'base_qty' => 1, 'purchasable' => true, 'sellable' => true], $sold]
            : [$sold];

        return $this->products->save(new Product, $a['create'] + ['range_id' => $rangeId, 'units' => $units]);
    }

    private function addPack(Product $product, array $pack): ProductUnit
    {
        return $product->units()->create(['name' => $pack['name'], 'base_qty' => $pack['size'], 'purchasable' => true, 'sellable' => true]);
    }

    private function addBarcode(?ProductUnit $unit, string $barcode): void
    {
        $unit?->barcodes()->create(['barcode' => $barcode, 'type' => Barcode::guessType($barcode)]);
    }

    /** Same shape as the Sync screen's Map action, always with sync off. */
    private function addListings(Product $product, array $listings, array &$variants): void
    {
        $product->load('units');
        foreach ($listings as $channel => $listing) {
            ChannelListing::create([
                'channel' => $channel, 'product_id' => $product->id,
                'product_unit_id' => $product->units->firstWhere('base_qty', $listing['pack_size'])->id,
                'external_product_id' => $listing['product_id'], 'external_variant_id' => $listing['variant_id'], 'external_sku' => $listing['sku'],
                'external_location_id' => $channel === 'ordermentum' ? config('services.ordermentum.location_id') : null,
                'sync_enabled' => false,
            ]);
            ChannelUnmappedVariant::where('channel', $channel)->where('external_variant_id', $listing['variant_id'])->whereNull('resolved_at')->update(['resolved_at' => now()]);
            $product->addSellChannel($channel);
            if ($channel === 'ordermentum') {
                $variants[] = $listing['variant_id'];
            }
        }
    }
}
