<?php

namespace Tests\Feature\Migration;

use App\Enums\MovementType;
use App\Enums\PurchaseOrderStatus;
use App\Models\Category;
use App\Models\ChannelListing;
use App\Models\Customer;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use App\Models\Supplier;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Support\LegacyFixture;
use Tests\Support\StockFixtures;
use Tests\TestCase;

class MigrateLegacyTest extends TestCase
{
    use LegacyFixture, RefreshDatabase, StockFixtures;

    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedStockReferenceData();
        $this->buildLegacyFixture();
        $this->storage = sys_get_temp_dir().'/bwms-migrate-'.uniqid();
        $this->app->useStoragePath($this->storage);
    }

    protected function tearDown(): void
    {
        $this->dropLegacyFixture();
        File::deleteDirectory($this->storage);
        parent::tearDown();
    }

    private function migrate(array $options = [])
    {
        return $this->artisan('bwms:migrate-legacy', ['--allow-writable-source' => true, ...$options]);
    }

    private function latestReport(string $ext = 'md'): string
    {
        $files = glob(storage_path("app/migration/*.{$ext}"));
        sort($files);

        return File::get(end($files));
    }

    private function csv(array $rows): string
    {
        $path = $this->storage.'/ob-'.uniqid().'.csv';
        File::ensureDirectoryExists($this->storage);
        File::put($path, implode("\n", $rows)."\n");

        return $path;
    }

    public function test_dry_run_reports_everything_and_keeps_nothing()
    {
        $this->migrate(['--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, Product::withTrashed()->count());
        $this->assertSame(0, Supplier::count());
        $this->assertSame(0, PurchaseOrder::count());

        $md = $this->latestReport();
        $this->assertStringContainsString('| products | 4 | 4 | 0 | 0 | 4 |', $md);
        $this->assertStringContainsString('## Products without Ordermentum mapping (1)', $md);
        $this->assertStringContainsString('PO-20260201-0002: stored total 60.00, subtotal + tax + shipping - discount = 54.50.', $md);
        $this->assertStringContainsString('Old Admin <admin@legacy.test>', $md);
        $this->assertStringContainsString('No opening balance file given', $md);

        $csv = $this->latestReport('csv');
        $this->assertStringContainsString('negative_legacy_on_hand', $csv);
        $this->assertStringContainsString('A-1-2', $csv);
    }

    public function test_commit_imports_reference_data_and_po_history_without_moving_stock()
    {
        $this->migrate(['--commit' => true])->assertSuccessful();

        $this->assertSame(2, Category::whereNotNull('legacy_id')->count());

        $vanilla = Product::where('legacy_id', 1)->sole();
        $this->assertSame(['DASH-VAN', 'unit', '4.5000', '9.90', 10, 24], [$vanilla->sku, $vanilla->base_unit, (string) $vanilla->avg_cost, (string) $vanilla->sell_price, $vanilla->reorder_point, $vanilla->reorder_qty]);
        $this->assertSame([[1, true, true]], $vanilla->units->map(fn ($u) => [$u->base_qty, $u->sellable, $u->purchasable])->all());

        $caramel = Product::where('legacy_id', 2)->sole();
        $this->assertSame('DASH-CAR', $caramel->sku, 'SKU uppercased and trimmed');
        $this->assertSame(['9300000000019'], $caramel->units->first()->barcodes->pluck('barcode')->all());

        $this->assertTrue(Product::withTrashed()->where('legacy_id', 4)->sole()->trashed());

        $listing = ChannelListing::where('product_id', $vanilla->id)->sole();
        $this->assertSame(['ordermentum', 'aaaaaaaa-0000-0000-0000-000000000001', 'DASH-VAN-OM', false],
            [$listing->channel, $listing->external_variant_id, $listing->external_sku, $listing->sync_enabled]);
        $this->assertSame(3, ChannelListing::count(), 'Tonic has no variant ID');

        $supplier = Supplier::where('legacy_id', 1)->sole();
        $this->assertSame("1 Lane\nBrisbane QLD 4000\nAustralia", $supplier->address);
        $this->assertStringContainsString('Mobile: 0400 000 000', $supplier->notes);
        $this->assertSame('Cafe One', Customer::where('legacy_id', 1)->sole()->name);

        $received = PurchaseOrder::where('legacy_id', 1)->sole();
        $this->assertSame([PurchaseOrderStatus::Closed, 'received', '99.00'], [$received->status, $received->legacy_status, (string) $received->total]);
        $this->assertSame([10, 5], $received->lines->pluck('qty_ordered')->all());
        $this->assertSame(0, (int) $received->lines->sum('qty_received_base'));
        $this->assertSame(PurchaseOrderStatus::Closed, PurchaseOrder::where('legacy_id', 2)->sole()->status);
        $this->assertStringContainsString('Legacy: shipping 5.00.', PurchaseOrder::where('legacy_id', 2)->sole()->notes);
        $this->assertSame(PurchaseOrderStatus::Cancelled, PurchaseOrder::where('legacy_id', 3)->sole()->status);

        $this->assertSame(0, StockMovement::count(), 'Legacy inventory and POs never move stock');
        $this->assertDatabaseHas('audit_logs', ['event' => 'legacy_migration_committed']);
    }

    public function test_rerunning_commit_is_idempotent()
    {
        $this->migrate(['--commit' => true])->assertSuccessful();
        $this->migrate(['--commit' => true])->assertSuccessful();

        $this->assertSame(4, Product::withTrashed()->count());
        $this->assertSame(3, PurchaseOrder::count());
        $this->assertSame(4, DB::table('purchase_order_lines')->count());
        $this->assertStringContainsString('| products | 4 | 0 | 4 | 0 | 4 |', $this->latestReport());
    }

    public function test_existing_sku_without_legacy_id_is_a_conflict_and_dependent_pos_are_skipped()
    {
        Product::factory()->create(['sku' => 'TONIC-1']);

        $this->migrate(['--commit' => true])->assertSuccessful();

        $this->assertNull(Product::where('sku', 'TONIC-1')->value('legacy_id'));
        $this->assertNull(PurchaseOrder::where('legacy_id', 3)->first(), 'PO 3 only contains the conflicting product');
        $md = $this->latestReport();
        $this->assertStringContainsString('Product SKU TONIC-1 (legacy #3) already exists', $md);
        $this->assertStringContainsString('PO-20260301-0003 (legacy #3): products not imported: legacy #3.', $md);
    }

    public function test_open_legacy_pos_keeps_pending_receivable()
    {
        $this->migrate(['--commit' => true, '--open-legacy-pos' => true])->assertSuccessful();

        $pending = PurchaseOrder::where('legacy_id', 2)->sole();
        $this->assertSame(PurchaseOrderStatus::Sent, $pending->status);
        $this->assertTrue($pending->status->canReceive());
    }

    public function test_opening_balances_come_from_the_stocktake_csv_and_are_idempotent()
    {
        $file = $this->csv(['sku,qty,bin,lot,best_before', 'DASH-VAN,30,,,', 'dash-car,12,QUARANTINE,,', 'TONIC-1,0,,,']);

        $this->migrate(['--commit' => true, '--opening-balances' => $file, '--cutover' => '2026-11-30 18:00'])->assertSuccessful();

        $vanilla = Product::where('sku', 'DASH-VAN')->sole();
        $this->assertSame(30, $this->balance($vanilla, $this->bin('STOCK')));
        $this->assertSame(12, $this->balance(Product::where('sku', 'DASH-CAR')->sole(), $this->bin('QUARANTINE')));
        $movement = StockMovement::where('product_id', $vanilla->id)->sole();
        $this->assertSame(MovementType::OpeningBalance, $movement->type);
        $this->assertSame('2026-11-30 08:00:00', $movement->occurred_at->utc()->format('Y-m-d H:i:s'), '18:00 Brisbane');
        $this->assertSame(2, StockMovement::count(), 'Zero rows post nothing');

        $this->migrate(['--commit' => true, '--opening-balances' => $file, '--cutover' => '2026-11-30 18:00'])->assertSuccessful();
        $this->assertSame(2, StockMovement::count());
        $this->assertStringContainsString('| opening_balances | 3 | 0 | 2 | 0 | 0 |', $this->latestReport());
    }

    public function test_changed_opening_quantity_on_rerun_is_refused_and_rolled_back()
    {
        $this->migrate(['--commit' => true, '--opening-balances' => $this->csv(['sku,qty', 'DASH-VAN,30']), '--cutover' => '2026-11-30 18:00'])->assertSuccessful();

        $this->migrate(['--commit' => true, '--opening-balances' => $this->csv(['sku,qty', 'DASH-VAN,31']), '--cutover' => '2026-11-30 18:00'])
            ->expectsOutputToContain('already used for a different movement')
            ->assertFailed();

        $this->assertSame(30, $this->balance(Product::where('sku', 'DASH-VAN')->sole(), $this->bin('STOCK')));
    }

    public function test_invalid_opening_rows_import_nothing()
    {
        $this->migrate(['--commit' => true, '--opening-balances' => $this->csv(['sku,qty', 'DASH-VAN,abc', 'DASH-VAN,1', 'DASH-VAN,2']), '--cutover' => '2026-11-30'])
            ->expectsOutputToContain('qty must be a whole number')
            ->expectsOutputToContain('duplicate of line 3')
            ->assertFailed();
        $this->assertSame(0, Product::count());

        $this->migrate(['--commit' => true, '--opening-balances' => $this->csv(['sku,qty,bin', 'NOPE,1,', 'DASH-VAN,1,NO-BIN']), '--cutover' => '2026-11-30'])
            ->expectsOutputToContain('opening balance row(s) are invalid')
            ->assertFailed();
        $this->assertSame(0, Product::count(), 'Rolled back as a whole');
        $this->assertStringContainsString('unknown SKU NOPE', $this->latestReport());
    }

    public function test_missing_source_column_stops_before_importing()
    {
        $this->buildLegacyFixture(fn ($pdo) => $pdo->exec('ALTER TABLE products DROP COLUMN ordermentum_variant_id'));

        $this->migrate(['--commit' => true])->expectsOutputToContain('products: ordermentum_variant_id')->assertFailed();
        $this->assertSame(0, Product::count());
    }

    public function test_source_guards_writable_user_same_database_and_read_only_session()
    {
        $this->artisan('bwms:migrate-legacy', ['--dry-run' => true])->expectsOutputToContain('can write. Use a SELECT-only user')->assertFailed();
        $this->artisan('bwms:migrate-legacy', ['--dry-run' => true, '--source' => config('database.default')])
            ->expectsOutputToContain('must be a different database')->assertFailed();
        $this->artisan('bwms:migrate-legacy')->expectsOutputToContain('exactly one of --dry-run or --commit')->assertFailed();

        $this->migrate(['--dry-run' => true])->assertSuccessful();
        $this->expectException(QueryException::class); // 1792: read-only transaction
        DB::connection('legacy')->table('users')->insert(['id' => 2, 'name' => 'x', 'email' => 'x', 'is_active' => 1, 'password' => 'x']);
    }

    public function test_sku_still_invalid_after_normalising_is_imported_unchanged_and_flagged()
    {
        $this->buildLegacyFixture(fn ($pdo) => $pdo->exec("UPDATE products SET sku = 'Tonic 200' WHERE id = 3"));

        $this->migrate(['--commit' => true])->assertSuccessful();

        $this->assertSame('Tonic 200', Product::where('legacy_id', 3)->value('sku'));
        $this->assertStringContainsString('"Tonic 200" (legacy #3): not a valid SKU', $this->latestReport());
        $this->assertStringNotContainsString('imported as TONIC 200', $this->latestReport());
    }

    public function test_stocktake_import_warns_about_products_still_on_the_default_pack()
    {
        $this->migrate(['--commit' => true, '--opening-balances' => $this->csv(['sku,qty', 'DASH-VAN,30', 'TONIC-1,0']), '--cutover' => '2026-11-30 18:00'])
            ->expectsOutputToContain('1 product(s) in the stocktake file are still on the default single "Unit" pack')
            ->assertSuccessful();

        $this->assertStringContainsString("still on the default single \"Unit\" pack (check Pack setup) (1)\n\n- DASH-VAN", $this->latestReport());
    }
}
