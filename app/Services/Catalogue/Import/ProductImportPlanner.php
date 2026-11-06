<?php

namespace App\Services\Catalogue\Import;

use App\Models\Barcode;
use App\Models\ChannelListing;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Range;
use App\Models\StockMovement;
use App\Services\Catalogue\PackSetupService;

/**
 * Works out what an import would do. Writes nothing.
 *
 * An item is one incoming product and pack, the same shape from every source:
 *   key, source, line, sku, name, range, categories, pack_text, base_unit, pack_name,
 *   pack_size, barcode, barcode_ignored, price, sell_ordermentum, sell_portal,
 *   external (channel => variant_id, product_id, sku), batch_tracked,
 *   shelf_life_days, active, errors. A null means the source has no opinion.
 *
 * A row is the decision on that item. The person's edits come in by item key, and
 * apply resolves each item again on live data, so a preview can't go stale silently.
 * `actions` is what ProductImportService carries out; `changes` and `diffs` say the
 * same thing for people. Only `actions` from `changes` happen by themselves: `diffs`
 * are WMS values that differ and stay as they are until the row's overwrite is ticked.
 */
class ProductImportPlanner
{
    public const GROUPS = ['new', 'update', 'mapping', 'conflict', 'skipped'];

    public const CHANNELS = ['ordermentum' => 'Ordermentum', 'portal' => 'Portal'];

    private const INACTIVE = ['ordermentum' => 'Deactivated on Ordermentum.', 'file' => 'Marked inactive in the file.'];

    private const VALID_SKU = '/^[A-Z0-9][A-Z0-9._\-\/]*$/';

    public function __construct(private PackParser $packs) {}

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, array<string, mixed>>  $edits  by item key
     * @return list<array<string, mixed>>
     */
    public function plan(array $items, array $edits = []): array
    {
        $context = $this->context($items);
        $shared = $this->sharedConflicts($items, $edits);
        $pending = [];
        $rows = [];
        foreach ($items as $item) {
            $rows[] = $this->resolve($item, $edits[$item['key']] ?? [], $context, $shared, $pending);
        }

        return $rows;
    }

    /**
     * One item on the data as it is now, inside apply's transaction. Earlier rows of
     * the same SKU have been saved by then, so they are found like any other product.
     *
     * @param  array<string, list<string>>  $shared  from sharedConflicts()
     */
    public function resolveNow(array $item, array $edit, array $shared): array
    {
        $pending = [];

        return $this->resolve($item, $edit, $this->context([$item]), $shared, $pending);
    }

    /** @return array<string, int> */
    public function summary(array $rows): array
    {
        $counts = array_fill_keys(self::GROUPS, 0);
        foreach ($rows as $row) {
            $counts[$row['group']]++;
        }

        return $counts + [
            'total' => count($rows),
            'check_pack' => count(array_filter($rows, fn ($r) => $r['check_pack'])),
            'needs_tick' => count(array_filter($rows, fn ($r) => $r['diffs'] !== [] && ! $r['overwrite'])),
            'barcodes_ignored' => count(array_filter($rows, fn ($r) => $r['barcode_ignored'])),
        ];
    }

    /** Rows without the internal actions, for the screen and the cache. */
    public function forDisplay(array $rows): array
    {
        return array_map(fn (array $row) => array_diff_key($row, ['actions' => true]), $rows);
    }

    /**
     * Problems only visible across rows: one external ID twice, two items of a channel
     * on one SKU and pack, one barcode on two SKUs. Rows left out of the import don't
     * count, so excluding one of a pair clears the other.
     *
     * @return array<string, list<string>> by item key
     */
    public function sharedConflicts(array $items, array $edits): array
    {
        $byId = $bySkuPack = $byBarcode = [];
        foreach ($items as $item) {
            $edit = $edits[$item['key']] ?? [];
            if (! ($edit['included'] ?? $item['active'] !== false)) {
                continue;
            }
            $sku = $this->normaliseSku($item['sku']);
            foreach ($item['external'] as $channel => $external) {
                $byId[$channel.'|'.$external['variant_id']][] = $item['key'];
                $size = $edit['pack_size'] ?? $item['pack_size'] ?? $this->packs->parse($item['pack_text'] ?? '')['pack_size'];
                $bySkuPack[$channel.'|'.$sku.'|'.$size][] = $item['key'];
            }
            if ($item['barcode'] && $sku !== '') {
                $byBarcode[$item['barcode']][$sku] = $item['key'];
            }
        }

        $shared = [];
        foreach ($byId as $id => $keys) {
            if (count($keys) > 1) {
                foreach ($keys as $key) {
                    $shared[$key][] = 'The same '.self::CHANNELS[strtok($id, '|')].' item appears more than once.';
                }
            }
        }
        foreach ($bySkuPack as $group => $keys) {
            if (count($keys) > 1 && explode('|', $group)[1] !== '') {
                [$channel, $sku] = explode('|', $group);
                foreach ($keys as $key) {
                    $shared[$key][] = count($keys).' '.self::CHANNELS[$channel]." items share SKU {$sku}. Fix one there, or exclude all but one here.";
                }
            }
        }
        foreach ($byBarcode as $barcode => $skus) {
            if (count($skus) > 1) {
                foreach ($skus as $key) {
                    $shared[$key][] = "Barcode {$barcode} is on more than one SKU in this import.";
                }
            }
        }

        return $shared;
    }

    /** Everything resolve() looks up, loaded once. */
    private function context(array $items): array
    {
        $skus = array_values(array_filter(array_map(fn ($i) => $this->normaliseSku($i['sku']), $items)));
        $ids = array_map(fn ($e) => (string) $e['variant_id'], array_merge([], ...array_map(fn ($i) => array_values($i['external']), $items)));
        $barcodes = array_values(array_filter(array_column($items, 'barcode')));

        $listings = ChannelListing::whereIn('external_variant_id', $ids)->get()->keyBy(fn (ChannelListing $l) => $l->channel.'|'.$l->external_variant_id);
        $codes = Barcode::whereIn('barcode', $barcodes)->with('unit')->get()->keyBy('barcode');
        $products = Product::withTrashed()->with(['units.barcodes', 'channelListings', 'range'])
            ->whereIn('sku', $skus)
            ->orWhereIn('id', [...$listings->pluck('product_id')->all(), ...$codes->pluck('unit.product_id')->all()])
            ->get();

        return [
            'listings' => $listings,
            'barcodes' => $codes,
            'by_id' => $products->keyBy('id'),
            'by_sku' => $products->keyBy(fn (Product $p) => strtoupper($p->sku)),
        ];
    }

    private function resolve(array $item, array $edit, array $context, array $shared, array &$pending): array
    {
        $sku = $this->normaliseSku($item['sku']);
        $conflicts = [...$item['errors'], ...($shared[$item['key']] ?? [])];
        $notes = [];

        // Matching: SKU, then mapping, then barcode. When they name different products a person decides.
        $bySku = $context['by_sku'][$sku] ?? null;
        $mapped = [];
        foreach ($item['external'] as $channel => $external) {
            if ($listing = $context['listings']["{$channel}|{$external['variant_id']}"] ?? null) {
                $mapped[$channel] = $listing;
            }
        }
        $byMapping = collect($mapped)->map(fn (ChannelListing $l) => $context['by_id'][$l->product_id] ?? null)->filter()->first();
        $code = $item['barcode'] ? ($context['barcodes'][$item['barcode']] ?? null) : null;
        $byBarcode = $code ? ($context['by_id'][$code->unit->product_id] ?? null) : null;

        $found = collect([$bySku, ...collect($mapped)->map(fn ($l) => $context['by_id'][$l->product_id] ?? null)->all(), $byBarcode])->filter()->unique('id')->values();
        if ($found->count() > 1) {
            $conflicts[] = $this->disagreement($sku, $bySku, $byMapping, $byBarcode);
        }
        /** @var Product|null $product */
        $product = $found->count() === 1 ? $found->first() : null;
        $matchedBy = $product ? ($bySku ? 'sku' : ($byMapping ? 'mapping' : 'barcode')) : null;
        if ($product?->trashed()) {
            $conflicts[] = "SKU {$product->sku} belongs to a deleted product.";
        }

        // An inactive item stays out, unless it speaks about a product already here: then it is a difference to show.
        $included = $edit['included'] ?? ($item['active'] !== false || $mapped !== [] || ($product && $item['external'] === []));
        $pack = $this->pack($item, $edit);
        $row = [
            'key' => $item['key'], 'line' => $item['line'], 'source' => $item['source'], 'sku' => $sku, 'name' => $item['name'],
            'product' => $product?->only(['id', 'sku', 'name']), 'matched_by' => $matchedBy,
            'pack_text' => $item['pack_text'], 'pack_name' => $pack['name'], 'pack_size' => $pack['size'], 'base_unit' => $pack['base_unit'],
            'pack_why' => $pack['why'], 'suggested' => $pack['suggested'], 'check_pack' => false,
            'range' => ($edit['range'] ?? null) ?: ($item['range'] ?: $product?->range?->name),
            'categories' => $item['categories'],
            'batch_tracked' => $edit['batch_tracked'] ?? $item['batch_tracked'] ?? $product?->is_batch_tracked ?? false,
            'included' => $included, 'overwrite' => (bool) ($edit['overwrite'] ?? false),
            'barcode_ignored' => $item['barcode_ignored'],
            'changes' => [], 'diffs' => [], 'notes' => [], 'group' => 'skipped', 'reason' => '', 'actions' => [],
        ];
        foreach (self::CHANNELS as $channel => $label) {
            $row["sell_{$channel}"] = $this->sellsOn($item, $edit, $channel) ?? ($product ? $product->sellsOn($channel) || isset($item['external'][$channel]) : isset($item['external'][$channel]));
        }

        $toMap = [];
        foreach ($item['external'] as $channel => $external) {
            if (! isset($mapped[$channel]) && $this->sellsOn($item, $edit, $channel) !== false) {
                $toMap[$channel] = $external;
            }
        }

        if ($conflicts === [] && ! $product) {
            if (isset($pending[$sku])) {
                $this->sameAsEarlierRow($row, $pending[$sku], $pack, $toMap, $sku);
            } else {
                $this->newProduct($row, $item, $edit, $pack, $toMap, $sku, $conflicts, $notes);
                if ($included && $conflicts === []) {
                    $pending[$sku] = ['label' => $this->label($item), 'packs' => $pack['size'] ? [$pack['size'] => true] : []];
                }
            }
        } elseif ($conflicts === []) {
            $this->existingProduct($row, $item, $edit, $product, $pack, $mapped, $toMap, $code, $conflicts, $notes);
        }

        $row['notes'] = $notes;
        $this->conclude($row, $item, $edit, $conflicts);

        return $row;
    }

    private function newProduct(array &$row, array $item, array $edit, array $pack, array $toMap, string $sku, array &$conflicts, array &$notes): void
    {
        if ($sku === '') {
            $conflicts[] = 'No SKU.';
        } elseif (! preg_match(self::VALID_SKU, $sku)) {
            $conflicts[] = "\"{$sku}\" is not a valid SKU (letters, digits, . _ - / only). Fix it at the source, or use an SKU that is in the WMS.";
        } elseif (mb_strlen($sku) > 64) {
            $conflicts[] = 'SKU is longer than 64 characters.';
        }
        if ($item['name'] === '') {
            $conflicts[] = 'A new product needs a name.';
        }
        if ($conflicts !== []) {
            return;
        }

        $row['check_pack'] = ! $pack['size'];
        $sold = [];
        foreach (self::CHANNELS as $channel => $label) {
            $sold[$channel] = (bool) ($row["sell_{$channel}"]);
            if ($sold[$channel] && ! isset($item['external'][$channel])) {
                $notes[] = "Sold on {$label}, but there is no {$label} ID, so no mapping is created.";
            }
        }
        $row['group'] = 'new';
        $row['changes'][] = 'New product'.($pack['size'] ? ': '.$this->packLabel($pack) : '');
        $row['changes'] = [...$row['changes'], ...array_map(fn ($c) => self::CHANNELS[$c].' mapping', array_keys($toMap))];
        $row['actions'] = [
            'create' => [
                'sku' => $sku, 'name' => $item['name'], 'base_unit' => mb_substr($pack['base_unit'] ?? 'each', 0, 32),
                'is_batch_tracked' => (bool) ($edit['batch_tracked'] ?? $item['batch_tracked'] ?? false),
                'shelf_life_days' => $item['shelf_life_days'], 'sell_price' => $item['price'],
                'is_active' => $item['active'] !== false, 'sell_channels' => $this->sellChannels($sold),
            ],
            'range' => $row['range'], 'pack' => $pack, 'barcode' => $item['barcode'], 'listings' => array_map(fn ($e) => $e + ['pack_size' => $pack['size']], $toMap),
        ];
    }

    /** A later row of an SKU this import is about to create. Only preview needs this: at apply it is an existing product. */
    private function sameAsEarlierRow(array &$row, array &$earlier, array $pack, array $toMap, string $sku): void
    {
        $row['check_pack'] = ! $pack['size'] && $toMap !== [];
        $row['group'] = 'mapping';
        $row['changes'] = array_map(fn ($c) => self::CHANNELS[$c].' mapping', array_keys($toMap));
        if ($pack['size'] && ! isset($earlier['packs'][$pack['size']])) {
            $row['group'] = 'update';
            $earlier['packs'][$pack['size']] = true;
            $row['changes'][] = 'Pack: '.$this->packLabel($pack);
        }
        $row['notes'][] = "Same product as {$earlier['label']}.";
        $row['actions'] = ['applies_when_earlier_row_is_saved' => true];
        $row['product'] = ['id' => null, 'sku' => $sku, 'name' => $row['name']];
    }

    /** @param  array<string, ChannelListing>  $mapped  this item's listings already in the WMS */
    private function existingProduct(array &$row, array $item, array $edit, Product $product, array $pack, array $mapped, array $toMap, ?Barcode $code, array &$conflicts, array &$notes): void
    {
        $overwrite = $row['overwrite'];
        $units = $product->units;
        $actions = ['product_id' => $product->id, 'attributes' => [], 'add_pack' => null, 'repoint' => [], 'listings' => [], 'barcode' => null, 'barcode_pack_size' => null, 'range' => null];

        // Which pack this item sells, and whether the WMS has it.
        $target = null;
        $addPack = null;
        if (PackSetupService::isOnDefaultPack($product)) {
            // "Unit" stands for the channel's sell unit here. A ×12 pack on top would mean twelve cartons,
            // and turning it into a real pack moves cost and PO lines, which is Pack setup's job.
            $target = $units->first();
            if ($pack['size'] > 1) {
                $notes[] = 'Still on the default Unit pack, so no pack is added. Set it up on Pack setup: this reads as '.$this->packLabel($pack).'.';
            }
        } elseif ($pack['size']) {
            $target = $units->firstWhere('base_qty', $pack['size']);
            $elsewhere = collect($mapped)->reject(fn (ChannelListing $l) => $units->firstWhere('id', $l->product_unit_id)?->base_qty === $pack['size']);
            if (! $target) {
                $clash = $units->first(fn (ProductUnit $u) => mb_strtolower($u->name) === mb_strtolower($pack['name']));
                if ($clash) {
                    $conflicts[] = "This product's {$clash->name} pack is ×{$clash->base_qty}, and the source says ×{$pack['size']}. Pack sizes can't change: give it another pack name here to add it as a new pack.";

                    return;
                }
                $addPack = $elsewhere->isEmpty() || $overwrite ? ['name' => $pack['name'], 'size' => $pack['size']] : null;
            }
            foreach ($elsewhere as $listing) {
                $current = $units->firstWhere('id', $listing->product_unit_id);
                $row['diffs'][] = ['field' => 'pack', 'label' => 'Pack', 'wms' => "{$current?->name} ×{$current?->base_qty}", 'incoming' => $this->packLabel($pack)];
                if ($overwrite) {
                    $actions['repoint'][] = ['listing_id' => $listing->id, 'channel' => $listing->channel];
                }
            }
        } elseif ($toMap !== []) {
            $row['check_pack'] = true; // which pack a new mapping sells is never assumed
        }

        if ($addPack) {
            $actions['add_pack'] = $addPack;
            $row['changes'][] = "Pack: {$addPack['name']} ×{$addPack['size']}";
        }

        $this->mappings($row, $actions, $product, $target, $addPack, $pack, $toMap, $conflicts);
        foreach ($actions['repoint'] as $repoint) {
            if ($target && ChannelListing::where('channel', $repoint['channel'])->where('product_id', $product->id)->where('product_unit_id', $target->id)->where('id', '!=', $repoint['listing_id'])->exists()) {
                $conflicts[] = "{$product->sku} ({$target->name}) is already mapped to another ".self::CHANNELS[$repoint['channel']].' item.';
            }
        }
        if ($conflicts !== []) {
            return;
        }

        // Empty in the WMS: filled in. Otherwise a difference to show, changed only with the tick.
        $apply = function (string $field, string $label, mixed $wms, mixed $incoming, array $set = []) use (&$row, &$actions, $overwrite) {
            $row['diffs'][] = ['field' => $field, 'label' => $label, 'wms' => $this->show($wms), 'incoming' => $this->show($incoming)];
            if ($overwrite) {
                $actions['attributes'] = $set + $actions['attributes'];
            }
        };

        $range = ($edit['range'] ?? null) ?: $item['range'];
        if ($range && ! $product->range_id) {
            $actions['range'] = $range;
            $row['changes'][] = "Range: {$range}";
        } elseif ($range && mb_strtolower($product->range->name) !== mb_strtolower($range)) {
            $apply('range', 'Range', $product->range->name, $range);
            $actions['range'] = $overwrite ? $range : null;
        }
        $shelfLife = $item['shelf_life_days'];
        if ($shelfLife && ! $product->shelf_life_days) {
            $actions['attributes']['shelf_life_days'] = $shelfLife;
            $row['changes'][] = "Shelf life: {$shelfLife} days";
        } elseif ($shelfLife && $product->shelf_life_days !== $shelfLife) {
            $apply('shelf_life_days', 'Shelf life', $product->shelf_life_days, $shelfLife, ['shelf_life_days' => $shelfLife]);
        }
        if ($item['price'] && ! (float) $product->sell_price && $target?->default_sale) {
            $actions['attributes']['sell_price'] = $item['price'];
            $row['changes'][] = "Sell price: {$item['price']}";
        }
        if ($item['barcode'] && ! $code && $target && $target->barcodes->isEmpty()) {
            $actions['barcode'] = $item['barcode'];
            $actions['barcode_pack_size'] = $target->base_qty;
            $row['changes'][] = "Barcode: {$item['barcode']}";
        }
        if ($item['name'] !== '' && $this->squash($item['name']) !== $this->squash($product->name)) {
            $apply('name', 'Name', $product->name, $item['name'], ['name' => $item['name']]);
        }
        $batch = $edit['batch_tracked'] ?? $item['batch_tracked'];
        if ($batch !== null && (bool) $batch !== $product->is_batch_tracked) {
            if (StockMovement::where('product_id', $product->id)->exists()) {
                $notes[] = 'Batch tracking stays as it is: this product already has stock movements.';
            } else {
                $apply('is_batch_tracked', 'Batch-tracked', $product->is_batch_tracked, (bool) $batch, ['is_batch_tracked' => (bool) $batch]);
            }
        }
        if ($item['active'] !== null && $item['active'] !== $product->is_active) {
            $apply('is_active', 'Active', $product->is_active, $item['active'], ['is_active' => $item['active']]);
        }

        // "Sold on": a new mapping adds its channel, as the Map action does. Anything else is a difference.
        $now = $wanted = [];
        foreach (self::CHANNELS as $channel => $label) {
            $now[$channel] = $product->sellsOn($channel) || isset($actions['listings'][$channel]);
            $wanted[$channel] = $this->sellsOn($item, $edit, $channel) ?? $now[$channel];
        }
        if ($wanted !== $now) {
            $apply('sell_channels', 'Sold on', $this->sellChannels($now), $this->sellChannels($wanted), ['sell_channels' => $this->sellChannels($wanted)]);
        }

        $row['actions'] = $actions;
    }

    private function mappings(array &$row, array &$actions, Product $product, ?ProductUnit $target, ?array $addPack, array $pack, array $toMap, array &$conflicts): void
    {
        foreach ($toMap as $channel => $external) {
            if (! $target && ! $addPack) {
                continue; // check_pack, or a pack that differs and needs the tick
            }
            if ($target && ! $target->sellable) {
                $conflicts[] = "{$product->sku} ({$target->name}) isn't a sellable pack.";
            } elseif ($target && $product->channelListings->where('channel', $channel)->where('product_unit_id', $target->id)->isNotEmpty()) {
                $conflicts[] = "{$product->sku} ({$target->name}) is already mapped to another ".self::CHANNELS[$channel].' item.';
            } else {
                $actions['listings'][$channel] = $external + ['pack_size' => $target?->base_qty ?? $pack['size']];
                $row['changes'][] = self::CHANNELS[$channel].' mapping';
            }
        }
    }

    /** Sets the row's group and reason once everything about it is known. */
    private function conclude(array &$row, array $item, array $edit, array $conflicts): void
    {
        if (! $row['included']) {
            $row['group'] = 'skipped';
            $row['reason'] = ($edit['included'] ?? null) === false ? 'Excluded in the preview.' : self::INACTIVE[$item['source']];
            $row['check_pack'] = false;
            $row['actions'] = [];
            $row['changes'] = [];

            return;
        }

        if ($conflicts !== []) {
            $row['group'] = 'conflict';
            $row['reason'] = implode(' ', array_unique($conflicts));
            $row['check_pack'] = false;
            $row['actions'] = [];

            return;
        }

        $mappingOnly = $row['changes'] !== [] && $row['diffs'] === [] && array_filter($row['changes'], fn ($c) => ! str_ends_with($c, ' mapping')) === [];
        if ($row['group'] === 'skipped') {
            $row['group'] = match (true) {
                $mappingOnly => 'mapping',
                $row['changes'] !== [] || $row['diffs'] !== [] || $row['check_pack'] => 'update',
                default => 'skipped',
            };
        }

        $reason = match ($row['group']) {
            'new' => 'New to the WMS.',
            'skipped' => 'Already up to date.',
            default => 'Matched by '.($row['matched_by'] ?? 'SKU').'.',
        };
        if ($row['check_pack']) {
            $reason = 'Check pack: '.$row['pack_why'].' '.$reason;
        }
        if ($row['diffs'] !== [] && ! $row['overwrite']) {
            $fields = implode(', ', array_map(fn ($d) => mb_strtolower($d['label']), $row['diffs']));
            $reason .= " Differs from the WMS: {$fields}. Nothing changes there unless overwrite is ticked.";
        }
        $row['reason'] = $reason;
    }

    /** @return array{name: ?string, size: ?int, base_unit: ?string, why: ?string, suggested: ?array} */
    private function pack(array $item, array $edit): array
    {
        $parsed = $this->packs->parse((string) $item['pack_text']);
        $size = $edit['pack_size'] ?? $item['pack_size'] ?? $parsed['pack_size'];
        $name = ($edit['pack_name'] ?? null) ?: ($item['pack_name'] ?? null) ?: $parsed['pack_name'];
        if ($size && ! $name) {
            $name = $size > 1 ? 'Case' : 'Each';
        }
        $baseUnit = ($edit['base_unit'] ?? null) ?: ($item['base_unit'] ?? null) ?: $parsed['base_unit'] ?: ($parsed['suggested']['base_unit'] ?? null);

        return ['name' => $size ? $name : null, 'size' => $size ? (int) $size : null, 'base_unit' => $baseUnit, 'why' => $parsed['why'], 'suggested' => $parsed['suggested']];
    }

    /** The edit, else the source, else null for no opinion. */
    private function sellsOn(array $item, array $edit, string $channel): ?bool
    {
        return $edit["sell_{$channel}"] ?? $item["sell_{$channel}"];
    }

    /** @param  array<string, bool>  $sold */
    private function sellChannels(array $sold): string
    {
        return match (true) {
            $sold['ordermentum'] && $sold['portal'] => 'both',
            $sold['ordermentum'] => 'ordermentum',
            $sold['portal'] => 'portal',
            default => 'none',
        };
    }

    private function disagreement(string $sku, ?Product $bySku, ?Product $byMapping, ?Product $byBarcode): string
    {
        $names = array_filter([
            $bySku ? "SKU {$sku} is {$bySku->name}" : null,
            $byMapping && $byMapping->id !== $bySku?->id ? "the channel item is mapped to {$byMapping->sku}" : null,
            $byBarcode && $byBarcode->id !== $bySku?->id ? "the barcode is on {$byBarcode->sku}" : null,
        ]);

        return ucfirst(implode(', but ', $names)).'.';
    }

    private function normaliseSku(?string $sku): string
    {
        return strtoupper(trim((string) $sku));
    }

    private function squash(string $text): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $text)));
    }

    private function packLabel(array $pack): string
    {
        return "{$pack['name']} ×{$pack['size']}";
    }

    private function label(array $item): string
    {
        return $item['line'] ? "line {$item['line']}" : $item['name'];
    }

    private function show(mixed $value): string
    {
        return is_bool($value) ? ($value ? 'Yes' : 'No') : (string) $value;
    }
}
