<?php

namespace App\Migration\Legacy;

use App\Enums\MovementType;
use App\Enums\OrderChannel;
use App\Enums\PurchaseOrderStatus;
use App\Models\Barcode;
use App\Models\Batch;
use App\Models\Category;
use App\Models\ChannelListing;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Rules\Gtin;
use App\Services\Stock\MovementDraft;
use App\Services\Stock\StockLedger;
use App\Services\Stock\StockQuery;
use DateTimeInterface;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Imports the legacy BWMS data (spec B9, audit 9.3) into the current database.
 * The caller owns the transaction (dry-run rolls it back).
 *
 * Re-runnable: every imported row carries legacy_id and is skipped next time.
 * Nothing existing is updated, except categories adopted by name. A target row
 * with the same code/SKU but no legacy_id is a conflict: reported, not merged.
 *
 * Stock: legacy `inventory` is never imported (audit C-3). Opening balances
 * come only from the cutover stocktake CSV (D-7), as opening_balance movements.
 * Legacy POs are history: no receipts, no stock movements.
 */
class LegacyImporter
{
    private MigrationReport $report;

    /** @var array<int, int> legacy id => new id */
    private array $categoryMap = [];

    /** @var array<int, Product> legacy id => product */
    private array $productMap = [];

    /** @var array<int, int> */
    private array $supplierMap = [];

    /**
     * @param  array{open_legacy_pos?: bool, cutover?: ?DateTimeInterface, opening_rows?: ?list<array<string, mixed>>,
     *               om_available?: ?array<string, int|string>, portal_stock?: ?array<string, int>}  $options
     */
    public function __construct(
        private Connection $source,
        private Location $site,
        private StockLedger $ledger,
        private StockQuery $stock,
        private array $options = [],
    ) {}

    public function run(MigrationReport $report): MigrationReport
    {
        $this->report = $report;

        $this->importCategories();
        $this->importProducts();
        $this->importSuppliers();
        $this->importCustomers();
        $this->importPurchaseOrders();
        $this->importOpeningBalances();
        $this->reportUsers();
        $this->buildProductRows();

        foreach (['categories' => Category::class, 'products' => Product::class, 'suppliers' => Supplier::class,
            'customers' => Customer::class, 'purchase_orders' => PurchaseOrder::class] as $entity => $model) {
            $query = $model === Product::class ? Product::withTrashed() : $model::query();
            $report->count($entity, 'target', $query->whereNotNull('legacy_id')->count());
        }

        return $report;
    }

    private function rows(string $table): Collection
    {
        return collect($this->source->table($table)->orderBy('id')->get());
    }

    private function importCategories(): void
    {
        $rows = $this->rows('product_categories');
        $this->report->count('categories', 'source', $rows->count());
        $pending = $rows->keyBy('id');

        // Parents first; a cycle or missing parent stops the loop and is reported.
        while ($pending->isNotEmpty()) {
            $progress = false;
            foreach ($pending as $id => $row) {
                if ($row->parent_id && ! isset($this->categoryMap[$row->parent_id])) {
                    continue;
                }
                $this->importCategory($row);
                $pending->forget($id);
                $progress = true;
            }
            if (! $progress) {
                foreach ($pending as $row) {
                    $this->report->issue('Skipped rows', "Category legacy #{$row->id} \"{$row->name}\": parent #{$row->parent_id} missing or circular.");
                    $this->report->count('categories', 'skipped');
                }
                break;
            }
        }
    }

    private function importCategory(object $row): void
    {
        if ($existing = Category::where('legacy_id', $row->id)->first()) {
            $this->categoryMap[$row->id] = $existing->id;
            $this->report->count('categories', 'existing');

            return;
        }
        $parentId = $row->parent_id ? $this->categoryMap[$row->parent_id] : null;
        $name = trim($row->name);
        $category = Category::where('parent_id', $parentId)->where('name', $name)->whereNull('legacy_id')->first();
        if ($category) {
            $this->report->issue('Notes', "Category \"{$name}\" already existed and was linked to legacy #{$row->id}.");
        }
        $category ??= new Category(['name' => $name, 'parent_id' => $parentId]);
        $category->forceFill(['legacy_id' => $row->id])->save();
        $this->categoryMap[$row->id] = $category->id;
        $this->report->count('categories', 'created');
    }

    private function importProducts(): void
    {
        $rows = $this->rows('products');
        $this->report->count('products', 'source', $rows->count());

        foreach ($rows as $row) {
            if ($existing = Product::withTrashed()->where('legacy_id', $row->id)->first()) {
                $this->productMap[$row->id] = $existing;
                $this->report->count('products', 'existing');

                continue;
            }

            $sku = strtoupper(trim($row->sku));
            if (Product::withTrashed()->where('sku', $sku)->exists()) {
                $this->report->issue('Conflicts', "Product SKU {$sku} (legacy #{$row->id}) already exists in BWMS without this legacy ID. Not imported.");
                $this->report->count('products', 'skipped');

                continue;
            }
            if ($sku !== $row->sku) {
                $this->report->issue('SKUs changed on import', "\"{$row->sku}\" imported as {$sku} (uppercase, trimmed).");
            }
            if (! preg_match('/^[A-Z0-9][A-Z0-9._\-\/]*$/', $sku)) {
                $this->report->issue('SKUs to fix by hand', "{$sku}: not a valid SKU format (letters, digits, . _ - / only). Editing this product will ask for a new SKU.");
            }

            $product = new Product([
                'sku' => $sku,
                'name' => trim($row->name),
                'description' => $row->description,
                'category_id' => $this->categoryMap[$row->category_id] ?? null,
                // D-1: the Ordermentum sell unit is the base unit until someone restructures the packs.
                'base_unit' => 'unit',
                'reorder_point' => $row->reorder_point !== null ? max(0, (int) $row->reorder_point) : null,
                'reorder_qty' => $row->reorder_quantity !== null ? max(0, (int) $row->reorder_quantity) : null,
                'sell_price' => (float) $row->price > 0 ? $row->price : null,
                'is_active' => (bool) $row->is_active && $row->deleted_at === null,
            ]);
            $product->forceFill(['avg_cost' => $row->cost, 'legacy_id' => $row->id])->save();
            if ($row->deleted_at !== null) {
                $product->delete();
            }

            $unit = new ProductUnit(['name' => 'Unit', 'base_qty' => 1, 'is_base' => true, 'purchasable' => true, 'sellable' => true,
                'default_purchase' => true, 'default_sale' => true]);
            $product->units()->save($unit);

            $this->importBarcode($row, $unit, $sku);
            $this->importListing($row, $product, $unit, $sku);

            $this->productMap[$row->id] = $product;
            $this->report->count('products', 'created');
        }
    }

    private function importBarcode(object $row, ProductUnit $unit, string $sku): void
    {
        $barcode = preg_replace('/\s+/', '', (string) $row->barcode);
        if ($barcode === '') {
            return;
        }
        if (! ctype_digit($barcode) || ! in_array(strlen($barcode), [8, 12, 13, 14], true) || ! Gtin::hasValidCheckDigit($barcode)) {
            $this->report->issue('Barcodes not imported', "{$sku}: \"{$barcode}\" is not a valid GTIN.");

            return;
        }
        if (Barcode::where('barcode', $barcode)->exists()) {
            $this->report->issue('Barcodes not imported', "{$sku}: {$barcode} is already used by another product.");

            return;
        }
        $unit->barcodes()->create(['barcode' => $barcode, 'type' => Barcode::guessType($barcode)]);
    }

    private function importListing(object $row, Product $product, ProductUnit $unit, string $sku): void
    {
        if (blank($row->ordermentum_variant_id)) {
            $this->report->issue('Products without Ordermentum mapping', "{$sku} {$product->name}");

            return;
        }
        $channel = OrderChannel::Ordermentum->value;
        if (ChannelListing::where('channel', $channel)->where('external_variant_id', $row->ordermentum_variant_id)->exists()) {
            $this->report->issue('Conflicts', "{$sku}: Ordermentum variant {$row->ordermentum_variant_id} is already mapped to another product. Listing not created.");

            return;
        }
        ChannelListing::create([
            'channel' => $channel,
            'product_id' => $product->id,
            'product_unit_id' => $unit->id,
            'external_product_id' => $row->ordermentum_product_id,
            'external_variant_id' => $row->ordermentum_variant_id,
            'external_sku' => $row->ordermentum_sku,
            'external_location_id' => config('services.ordermentum.location_id'),
            'sync_enabled' => false,
        ]);
    }

    private function importSuppliers(): void
    {
        $rows = $this->rows('suppliers');
        $this->report->count('suppliers', 'source', $rows->count());

        foreach ($rows as $row) {
            if ($existing = Supplier::where('legacy_id', $row->id)->first()) {
                $this->supplierMap[$row->id] = $existing->id;
                $this->report->count('suppliers', 'existing');

                continue;
            }
            $code = strtoupper(trim($row->supplier_code));
            if (Supplier::where('code', $code)->exists()) {
                $this->report->issue('Conflicts', "Supplier code {$code} (legacy #{$row->id}) already exists without this legacy ID. Not imported; its POs are skipped.");
                $this->report->count('suppliers', 'skipped');

                continue;
            }

            $supplier = new Supplier([
                'code' => $code,
                'name' => trim($row->company_name),
                'contact_name' => $row->contact_name ?: null,
                'email' => $row->email ?: null,
                'phone' => $row->phone ?: ($row->mobile ?: null),
                'address' => $this->address($row),
                'payment_terms' => $row->payment_terms ?: null,
                'notes' => $this->notes($row, $row->phone && $row->mobile ? $row->mobile : null),
                'is_active' => (bool) $row->is_active && $row->deleted_at === null,
            ]);
            $supplier->forceFill(['legacy_id' => $row->id])->save();
            $this->supplierMap[$row->id] = $supplier->id;
            $this->report->count('suppliers', 'created');
        }
    }

    private function importCustomers(): void
    {
        $rows = $this->rows('customers');
        $this->report->count('customers', 'source', $rows->count());

        foreach ($rows as $row) {
            if (Customer::where('legacy_id', $row->id)->exists()) {
                $this->report->count('customers', 'existing');

                continue;
            }
            $code = strtoupper(trim($row->customer_code));
            if (Customer::where('code', $code)->exists()) {
                $this->report->issue('Conflicts', "Customer code {$code} (legacy #{$row->id}) already exists without this legacy ID. Not imported.");
                $this->report->count('customers', 'skipped');

                continue;
            }

            $customer = new Customer([
                'code' => $code,
                'name' => trim($row->company_name) ?: trim($row->name),
                'contact_name' => $row->name ?: null,
                'email' => $row->email ?: null,
                'phone' => $row->phone ?: ($row->mobile ?: null),
                'address' => $this->address($row),
                'notes' => $this->notes($row, $row->phone && $row->mobile ? $row->mobile : null, $row->payment_terms),
                'is_active' => (bool) $row->is_active && $row->deleted_at === null,
            ]);
            $customer->forceFill(['legacy_id' => $row->id])->save();
            $this->report->count('customers', 'created');
        }
    }

    private function importPurchaseOrders(): void
    {
        $orders = $this->rows('purchase_orders');
        $items = collect($this->source->table('purchase_order_items')->orderBy('id')->get())->groupBy('purchase_order_id');
        $deletedWarehouses = collect($this->source->table('warehouses')->whereNotNull('deleted_at')->pluck('id'))->all();
        $this->report->count('purchase_orders', 'source', $orders->count());
        $this->report->count('purchase_order_lines', 'source', $items->flatten(1)->count());

        foreach ($orders as $row) {
            $lines = $items[$row->id] ?? collect();
            if (PurchaseOrder::where('legacy_id', $row->id)->exists()) {
                $this->report->count('purchase_orders', 'existing');
                $this->report->count('purchase_order_lines', 'existing', $lines->count());

                continue;
            }
            $skip = fn (string $why) => [
                $this->report->issue('Skipped rows', "PO {$row->order_number} (legacy #{$row->id}): {$why}"),
                $this->report->count('purchase_orders', 'skipped'),
                $this->report->count('purchase_order_lines', 'skipped', $lines->count()),
            ];
            if (! isset($this->supplierMap[$row->supplier_id])) {
                $skip("supplier legacy #{$row->supplier_id} was not imported.");

                continue;
            }
            if ($missing = $lines->reject(fn ($l) => isset($this->productMap[$l->product_id]))->pluck('product_id')->unique()->values()->all()) {
                $skip('products not imported: legacy #'.implode(', #', $missing).'.');

                continue;
            }
            if (PurchaseOrder::where('number', $row->order_number)->exists()) {
                $this->report->issue('Conflicts', "PO number {$row->order_number} already exists without this legacy ID. Not imported.");
                $this->report->count('purchase_orders', 'skipped');
                $this->report->count('purchase_order_lines', 'skipped', $lines->count());

                continue;
            }
            if (in_array($row->warehouse_id, $deletedWarehouses, true)) {
                $this->report->issue('Notes', "PO {$row->order_number} was on a deleted legacy warehouse; imported against {$this->site->name}.");
            }

            [$status, $open] = $this->poStatus($row->status);
            $extras = array_filter([
                (float) $row->shipping != 0 ? "shipping {$row->shipping}" : null,
                (float) $row->discount != 0 ? "discount {$row->discount}" : null,
            ]);

            $po = new PurchaseOrder([
                'supplier_id' => $this->supplierMap[$row->supplier_id],
                'site_id' => $this->site->id,
                'order_date' => $row->order_date,
                'expected_date' => $row->expected_date,
                'notes' => trim(implode("\n", array_filter([$row->notes, $extras ? 'Legacy: '.implode(', ', $extras).'.' : null]))) ?: null,
            ]);
            $po->forceFill([
                'number' => $row->order_number,
                'status' => $status,
                // Stored totals are historical, kept as recorded (audit H-11); mismatches are reported.
                'subtotal' => $row->subtotal,
                'tax_total' => $row->tax,
                'total' => $row->total,
                'closed_at' => $open ? null : $row->updated_at,
                'close_reason' => $open ? null : 'Imported from legacy BWMS',
                'legacy_id' => $row->id,
                'legacy_status' => $row->status,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ])->save();

            $sum = 0.0;
            foreach ($lines->values() as $i => $line) {
                $product = $this->productMap[$line->product_id];
                $lineSubtotal = round($line->quantity * (float) $line->unit_price - (float) $line->discount, 2);
                $sum += $lineSubtotal;
                $po->lines()->create([
                    'product_id' => $product->id,
                    'product_unit_id' => $product->units()->where('base_qty', 1)->value('id'),
                    'qty_ordered' => $line->quantity,
                    'base_qty_per_unit' => 1,
                    'qty_ordered_base' => $line->quantity,
                    'unit_cost' => $line->unit_price,
                    'tax_rate' => $row->tax_rate,
                    'line_subtotal' => $lineSubtotal,
                    // Legacy received_quantity is unusable (audit C-1): nothing was ever received through it.
                    'qty_received_base' => 0,
                    'sort_order' => $i,
                ]);
            }

            if (abs($sum - (float) $row->subtotal) > 0.005) {
                $this->report->issue('PO total mismatches', sprintf('%s: stored subtotal %.2f, sum of lines %.2f.', $row->order_number, $row->subtotal, $sum));
            }
            $expectedTotal = (float) $row->subtotal + (float) $row->tax + (float) $row->shipping - (float) $row->discount;
            if (abs($expectedTotal - (float) $row->total) > 0.005) {
                $this->report->issue('PO total mismatches', sprintf('%s: stored total %.2f, subtotal + tax + shipping - discount = %.2f.', $row->order_number, $row->total, $expectedTotal));
            }
            if (in_array($row->status, ['draft', 'pending'], true)) {
                $this->report->issue('Legacy draft/pending POs (decide: history or still open?)', sprintf(
                    '%s, ordered %s, expected %s, total %.2f: imported as %s.', $row->order_number, $row->order_date, $row->expected_date ?? '-', $row->total, $status->label()
                ));
            }

            $this->report->count('purchase_orders', 'created');
            $this->report->count('purchase_order_lines', 'created', $lines->count());
        }
    }

    /** @return array{0: PurchaseOrderStatus, 1: bool} status, is open */
    private function poStatus(string $legacy): array
    {
        $open = $this->options['open_legacy_pos'] ?? false;

        return match ($legacy) {
            'cancelled' => [PurchaseOrderStatus::Cancelled, false],
            'draft' => $open ? [PurchaseOrderStatus::Draft, true] : [PurchaseOrderStatus::Closed, false],
            'pending' => $open ? [PurchaseOrderStatus::Sent, true] : [PurchaseOrderStatus::Closed, false],
            default => [PurchaseOrderStatus::Closed, false], // 'received' never moved stock (audit C-1)
        };
    }

    /** D-7: opening balances only from the cutover stocktake CSV. Rows were parsed by OpeningBalanceFile. */
    private function importOpeningBalances(): void
    {
        $rows = $this->options['opening_rows'] ?? null;
        if ($rows === null) {
            $this->report->notes[] = 'No opening balance file given: no stock was imported (D-7).';

            return;
        }
        $cutover = $this->options['cutover'] ?? throw new RuntimeException('--cutover is required with --opening-balances.');

        $drafts = [];
        $errors = [];
        foreach ($rows as $r) {
            $where = "line {$r['line']}";
            $product = Product::where('sku', strtoupper($r['sku']))->first();
            if (! $product) {
                $errors[] = "{$where}: unknown SKU {$r['sku']}.";

                continue;
            }
            $site = $r['site'] ? Location::whereNull('parent_id')->where('code', strtoupper($r['site']))->first() : $this->site;
            $bin = $site ? Location::bins()->whereIn('id', $this->stock->binIdsUnder($site))->where('code', strtoupper($r['bin'] ?: 'STOCK'))->first() : null;
            if (! $bin || ! $bin->is_active) {
                $errors[] = "{$where}: no active bin ".($r['bin'] ?: 'STOCK').' at '.($r['site'] ?: $this->site->code).'.';

                continue;
            }
            if ($product->is_batch_tracked && (! $r['lot'] || ! $r['best_before'])) {
                $errors[] = "{$where}: {$product->sku} is batch-tracked; lot and best_before are required.";

                continue;
            }
            if ($r['qty'] === 0) {
                continue;
            }
            $batch = $r['lot'] ? Batch::findOrCreateFor($product, $r['lot'], $r['best_before'] ?: null) : null;
            $drafts[] = new MovementDraft(
                product: $product,
                location: $bin,
                qty: $r['qty'],
                type: MovementType::OpeningBalance,
                idempotencyKey: 'opening:'.$product->id.':'.$bin->id.':'.($batch?->id ?? 0),
                batch: $batch,
                sourceType: 'legacy_migration',
                unitCost: (string) $product->avg_cost,
                occurredAt: $cutover,
                note: 'Cutover stocktake',
            );
        }

        if ($errors !== []) {
            foreach ($errors as $e) {
                $this->report->issue('Opening balance errors (nothing posted)', $e);
            }
            throw new OpeningBalanceRejected(count($errors).' opening balance row(s) are invalid; see the report.');
        }

        $this->report->count('opening_balances', 'source', count($rows));
        // Same key + same content returns the existing movement, so a re-run posts nothing new;
        // a changed quantity for an already-posted row is refused (IdempotencyConflict).
        $existing = StockMovement::whereIn('idempotency_key', array_map(fn ($d) => $d->idempotencyKey, $drafts))->count();
        foreach (array_chunk($drafts, 50) as $chunk) {
            $this->ledger->post($chunk);
        }
        $this->report->count('opening_balances', 'existing', $existing);
        $this->report->count('opening_balances', 'created', count($drafts) - $existing);
    }

    private function reportUsers(): void
    {
        foreach ($this->source->table('users')->orderBy('id')->get() as $u) {
            $this->report->issue('Legacy users (not migrated; invite them from Admin > Users)', "{$u->name} <{$u->email}>".($u->is_active ? '' : ' (inactive)').($u->role ? ", legacy role: {$u->role}" : ''));
        }
    }

    private function buildProductRows(): void
    {
        $onHand = collect($this->source->table('inventory')->selectRaw('product_id, SUM(quantity_on_hand) AS q')->groupBy('product_id')->pluck('q', 'product_id'));
        // Legacy bin labels (e.g. A-1-1) are shown for the stocktake only; no bins are created from them.
        $bins = collect($this->source->table('inventory')->whereNotNull('bin_location')->where('bin_location', '<>', '')
            ->selectRaw('product_id, GROUP_CONCAT(DISTINCT bin_location) AS b')->groupBy('product_id')->pluck('b', 'product_id'));
        $opening = StockMovement::where('type', MovementType::OpeningBalance)->selectRaw('product_id, SUM(qty) AS q')->groupBy('product_id')->pluck('q', 'product_id');
        $om = $this->options['om_available'] ?? null;
        $portal = $this->options['portal_stock'] ?? null;

        foreach ($this->rows('products') as $row) {
            $product = $this->productMap[$row->id] ?? null;
            $legacyOnHand = $onHand[$row->id] ?? null;
            $flags = array_filter([
                blank($row->ordermentum_variant_id) ? 'no_ordermentum_mapping' : null,
                (float) $row->cost == 0.0 ? 'zero_cost' : null,
                $legacyOnHand !== null && (int) $legacyOnHand < 0 ? 'negative_legacy_on_hand' : null,
                $legacyOnHand === null ? 'no_legacy_inventory_row' : null,
                ! in_array(strtolower(trim($row->unit_of_measure)), ['unit'], true) ? 'unit_'.(trim($row->unit_of_measure) ?: 'blank') : null,
                $product ? null : 'not_imported',
            ]);
            if ($legacyOnHand !== null && (int) $legacyOnHand < 0) {
                $this->report->count('negative_legacy_balances', 'source');
            }
            if ((float) $row->cost == 0.0) {
                $this->report->count('zero_cost_products', 'source');
            }

            $this->report->products[] = [
                'sku' => $product?->sku ?? strtoupper(trim($row->sku)),
                'legacy_sku' => $row->sku,
                'name' => $row->name,
                'legacy_id' => $row->id,
                'product_id' => $product?->id,
                'ordermentum_variant_id' => $row->ordermentum_variant_id,
                'cost' => $row->cost,
                'legacy_unit' => $row->unit_of_measure,
                'legacy_on_hand' => $legacyOnHand,
                'legacy_bin' => $bins[$row->id] ?? null,
                'opening_balance' => $product ? ($opening[$product->id] ?? null) : null,
                'ordermentum_available' => $om === null ? 'not checked' : ($om[$row->ordermentum_variant_id] ?? ''),
                'portal_stock_qty' => $portal === null ? 'not checked' : ($portal[strtoupper(trim($row->sku))] ?? ''),
                'flags' => implode(' ', $flags),
            ];
        }
    }

    private function address(object $row): ?string
    {
        $line2 = trim(implode(' ', array_filter([trim((string) $row->city), trim((string) $row->state), trim((string) $row->postal_code)])));

        return trim(implode("\n", array_filter([trim((string) $row->address), $line2, trim((string) $row->country)]))) ?: null;
    }

    private function notes(object $row, ?string $mobile, ?string $paymentTerms = null): ?string
    {
        return trim(implode("\n", array_filter([
            $row->notes,
            $mobile ? "Mobile: {$mobile}" : null,
            $row->tax_id ? "Tax ID / ABN: {$row->tax_id}" : null,
            $paymentTerms ? "Payment terms: {$paymentTerms}" : null,
            $row->credit_limit !== null && (float) $row->credit_limit > 0 ? "Credit limit: {$row->credit_limit}" : null,
        ]))) ?: null;
    }
}
