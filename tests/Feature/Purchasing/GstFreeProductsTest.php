<?php

namespace Tests\Feature\Purchasing;

use App\Authorization\Permissions;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\SalesHistory;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Purchasing\CashOutReport;
use App\Services\Purchasing\PurchaseOrderService;
use App\Services\Purchasing\ReorderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\Support\StockFixtures;
use Tests\TestCase;

/** products.gst_free: the PO line's default GST, inc-GST cash out, the Xero CSV import, the product list. */
class GstFreeProductsTest extends TestCase
{
    use RefreshDatabase, StockFixtures;

    private User $manager;

    private Supplier $supplier;

    private Product $milk;   // GST-free, $3 each

    private Product $bread;  // taxable, $5 each

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-16 09:00', 'Australia/Brisbane')); // Monday
        $this->seedStockReferenceData();
        $this->manager = $this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER);
        $this->supplier = Supplier::factory()->create(['lead_time_days' => 5, 'review_period_days' => 7, 'payment_terms_days' => 7]);
        $this->milk = Product::factory()->create(['sku' => 'MILK', 'supplier_id' => $this->supplier->id, 'sell_price' => 3, 'gst_free' => true]);
        $this->bread = Product::factory()->create(['sku' => 'BREAD', 'supplier_id' => $this->supplier->id, 'sell_price' => 5]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        foreach ($this->files as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    private function history(Product $p, int $perDay): void
    {
        for ($i = 1; $i <= 56; $i++) {
            SalesHistory::create(['source' => SalesHistory::SOURCE_ORDERMENTUM, 'product_id' => $p->id, 'sale_date' => Carbon::parse('2026-11-16')->subDays($i)->toDateString(),
                'qty_base' => $perDay, 'external_ref' => "om-{$p->id}-{$i}", 'line_ref' => '1']);
        }
    }

    private function line(Product $p, ?int $tax = null): array
    {
        return ['product_id' => $p->id, 'product_unit_id' => $p->units()->value('id'), 'qty_ordered' => 10, 'unit_cost' => '2'] + ($tax === null ? [] : ['tax_rate' => $tax]);
    }

    private function csv(string $body): string
    {
        $path = tempnam(sys_get_temp_dir(), 'gst');
        file_put_contents($path, $body);

        return $this->files[] = $path;
    }

    // --- PO line GST default ----------------------------------------------------------------------------------

    public function test_a_po_line_defaults_to_0_percent_for_gst_free_and_10_otherwise_and_the_buyer_can_change_it()
    {
        $po = app(PurchaseOrderService::class)->saveDraft(null, [
            'supplier_id' => $this->supplier->id, 'site_id' => $this->site()->id, 'order_date' => '2026-11-16',
            'lines' => [$this->line($this->milk), $this->line($this->bread), $this->line($this->milk, 10), $this->line($this->bread, 0)],
        ], $this->manager);

        $this->assertSame(['0.00', '10.00', '10.00', '0.00'], $po->lines->pluck('tax_rate')->all());
        $this->assertSame('80.00', $po->subtotal);
        $this->assertSame('4.00', $po->tax_total); // GST on the two 10% lines of $20

        // The PO form gets the flag with each product it adds.
        $this->actingAs($this->manager)->getJson(route('products.search', ['q' => 'MILK']))->assertJsonPath('0.gst_free', true);
    }

    public function test_suggested_po_lines_take_the_products_gst_default()
    {
        $this->history($this->milk, 2);
        $this->history($this->bread, 1);

        $po = app(ReorderService::class)->draftPurchaseOrders()->sole()['po'];

        $rates = $po->lines->mapWithKeys(fn ($l) => [$l->product->sku => $l->tax_rate])->all();
        $this->assertSame(['BREAD' => '10.00', 'MILK' => '0.00'], $rates); // lines in SKU order
        $bread = $po->lines->firstWhere('product_id', $this->bread->id);
        $this->assertSame(round((float) $bread->line_subtotal * 0.1, 2), (float) $po->tax_total, 'GST on the bread line only');
    }

    // --- Cash out inc GST ---------------------------------------------------------------------------------------

    public function test_cash_out_inflow_and_net_are_inc_gst_with_ex_gst_alongside()
    {
        $this->history($this->milk, 2);  // 2 × 7 × $3 = $42 a week, GST-free
        $this->history($this->bread, 1); // 1 × 7 × $5 = $35 a week, + $3.50 GST
        $service = app(PurchaseOrderService::class);
        $po = $service->saveDraft(null, [
            'supplier_id' => $this->supplier->id, 'site_id' => $this->site()->id, 'order_date' => '2026-11-16', 'expected_date' => '2026-11-20',
            'lines' => [$this->line($this->milk), $this->line($this->bread)], // $20 GST-free + $20 + $2 GST
        ], $this->manager);
        $service->approve($po, $this->manager, true, 'test'); // due 27 Nov

        $r = app(CashOutReport::class)->build();

        $this->assertSame(80.5, $r['inflow_per_week']);
        $this->assertSame(77.0, $r['inflow_per_week_ex']);
        $this->assertSame(['start' => '2026-11-23', 'end' => '2026-11-29', 'payments' => 42.0, 'payments_ex' => 40.0,
            'inflow' => 80.5, 'inflow_ex' => 77.0, 'net' => 38.5, 'net_ex' => 37.0], $r['weeks'][1]);
        $this->assertSame(['inflow' => 80.5, 'inflow_ex' => 77.0, 'net' => 80.5, 'net_ex' => 77.0],
            array_intersect_key($r['weeks'][0], array_flip(['inflow', 'inflow_ex', 'net', 'net_ex'])));
    }

    // --- products:import-gst-flags -----------------------------------------------------------------------------

    public function test_import_sets_matching_skus_and_reports_unmatched_and_bad_rows()
    {
        $tea = Product::factory()->create(['sku' => 'TEA', 'gst_free' => true]);
        $file = $this->csv("\xEF\xBB\xBFsku,gst_free\nbread,yes\nMILK,1\nTEA,0\nGHOST,1\nNOPE,0\nCOFFEE,maybe\n\n");

        $this->artisan('products:import-gst-flags', ['csv' => $file])
            ->expectsOutputToContain('2 SKU(s) not in BWMS, skipped: GHOST, NOPE')
            ->expectsOutputToContain('Skipped line 7')
            ->expectsOutputToContain('2 product(s) changed, 1 already right, 2 unmatched, 1 invalid row(s).')
            ->assertSuccessful();

        $this->assertTrue($this->bread->fresh()->gst_free, 'matched case-insensitively');
        $this->assertTrue($this->milk->fresh()->gst_free, 'unchanged');
        $this->assertFalse($tea->fresh()->gst_free);
        $this->assertSame(0, Product::whereIn('sku', ['GHOST', 'NOPE'])->count(), 'unmatched SKUs are not created');
    }

    public function test_import_dry_run_changes_nothing()
    {
        $file = $this->csv("BREAD,1\nMILK,0\n");

        $this->artisan('products:import-gst-flags', ['csv' => $file, '--dry-run' => true])
            ->expectsOutputToContain('2 product(s) would change, 0 already right, 0 unmatched, 0 invalid row(s). Nothing changed (dry run).')
            ->assertSuccessful();

        $this->assertFalse($this->bread->fresh()->gst_free);
        $this->assertTrue($this->milk->fresh()->gst_free);
    }

    public function test_import_skips_a_sku_given_twice_and_fails_on_a_missing_file()
    {
        $file = $this->csv("BREAD,1\nBREAD,0\n");

        $this->artisan('products:import-gst-flags', ['csv' => $file])->expectsOutputToContain('BREAD is also on line 1')->assertSuccessful();
        $this->assertFalse($this->bread->fresh()->gst_free);

        $this->artisan('products:import-gst-flags', ['csv' => '/no/such/file.csv'])->assertFailed();
    }

    // --- Product form and list -----------------------------------------------------------------------------------

    public function test_the_product_list_filters_by_gst_and_the_form_saves_the_flag()
    {
        $admin = $this->userWithRole(Permissions::ROLE_ADMIN);
        $this->actingAs($admin)->get(route('products.index', ['gst' => 'free']))->assertInertia(fn (AssertableInertia $p) => $p
            ->has('products.data', 1)->where('products.data.0.sku', 'MILK')->where('products.data.0.gst_free', true));
        $this->actingAs($admin)->get(route('products.index', ['gst' => 'taxable']))->assertInertia(fn (AssertableInertia $p) => $p
            ->has('products.data', 1)->where('products.data.0.sku', 'BREAD'));

        $this->actingAs($admin)->get(route('products.edit', $this->bread))->assertInertia(fn (AssertableInertia $p) => $p->where('product.gst_free', false));
        $unit = $this->bread->units()->first();
        $this->actingAs($admin)->put(route('products.update', $this->bread), [
            'sku' => 'BREAD', 'name' => $this->bread->name, 'base_unit' => 'each', 'gst_free' => true,
            'units' => [['id' => $unit->id, 'name' => $unit->name, 'base_qty' => 1, 'purchasable' => true, 'sellable' => true]],
        ])->assertSessionHasNoErrors();
        $this->assertTrue($this->bread->fresh()->gst_free);
        $this->assertSame(0, PurchaseOrder::count());
    }
}
