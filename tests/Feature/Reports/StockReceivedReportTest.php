<?php

namespace Tests\Feature\Reports;

use App\Authorization\Permissions;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Purchasing\PurchaseOrderService;
use App\Services\Purchasing\ReceivingService;
use App\Services\Reports\Insights\InsightFilters;
use App\Services\Reports\Insights\StockReceivedReport;
use App\Support\BusinessDate;
use Database\Factories\ProductFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Support\StockFixtures;
use Tests\TestCase;

class StockReceivedReportTest extends TestCase
{
    use RefreshDatabase, StockFixtures;

    private User $manager;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedStockReferenceData();
        $this->manager = $this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER);
        $this->supplier = Supplier::factory()->create(['name' => 'Farm Co', 'lead_time_days' => 3]);
    }

    private function day(int $offset): string
    {
        return Carbon::parse(BusinessDate::today())->addDays($offset)->toDateString();
    }

    /** A sent PO for $cartons cartons of 12 at $24 a carton. */
    private function po(Product $product, int $cartons, ?string $expected): PurchaseOrder
    {
        $s = app(PurchaseOrderService::class);
        $po = $s->saveDraft(null, [
            'supplier_id' => $this->supplier->id, 'site_id' => $this->site()->id, 'order_date' => $this->day(0), 'expected_date' => $expected,
            'lines' => [['product_id' => $product->id, 'product_unit_id' => ProductFactory::unitOf($product, 12)->id, 'qty_ordered' => $cartons, 'unit_cost' => '24.00']],
        ], $this->manager);

        return $s->markSent($s->approve($po, $this->manager, true, 'test'), $this->manager);
    }

    private function receive(PurchaseOrder $po, array $qty, bool $over = false): void
    {
        app(ReceivingService::class)->receive($po, $this->manager, [['purchase_order_line_id' => $po->lines->first()->id] + $qty], (string) Str::uuid(), allowOver: $over);
    }

    private function report(array $filters = []): array
    {
        $f = new InsightFilters($filters['from'] ?? $this->day(-7), $filters['to'] ?? $this->day(0), supplierId: $filters['supplier_id'] ?? null, productId: $filters['product_id'] ?? null);

        return app(StockReceivedReport::class)->build($f);
    }

    public function test_receipts_by_sku_supplier_and_po_with_damage_rejects_and_short_over()
    {
        $beans = Product::factory()->withPack('Carton', 12)->create(['sku' => 'BEAN']);
        $short = $this->po($beans, 10, $this->day(2));
        $this->receive($short, ['qty_accepted_base' => 96, 'qty_damaged_base' => 12, 'qty_rejected_base' => 12, 'discrepancy_reason' => 'damaged']);
        app(PurchaseOrderService::class)->closeShort($short->fresh(), $this->manager, 'Supplier out');
        $over = $this->po($beans, 5, $this->day(-1));
        $this->receive($over, ['qty_accepted_base' => 72], over: true);

        $r = $this->report();
        $t = $r['totals'];
        // 108 + 72 base received (damaged counts as received), at $2 a unit.
        $this->assertSame([180, 360.0, 2, 12, 12, 12, 12, 0], [$t['units'], $t['cost'], $t['receipts'], $t['damaged'], $t['rejected'], $t['over'], $t['short'], $t['outstanding']]);
        $this->assertSame(24.0, $t['damaged_cost']);

        $sku = $r['groups']['sku'][0];
        $this->assertSame(trim("BEAN {$beans->name}"), $sku['label']);
        $this->assertSame([15.0, 180, 360.0, 2, 180], [$sku['packs'], $sku['units'], $sku['cost'], $sku['receipts'], $sku['ordered']]);
        $this->assertSame('Farm Co', $r['groups']['supplier'][0]['label']);
        $byPo = collect($r['groups']['po'])->keyBy('po_id');
        $this->assertSame([12, 0], [$byPo[$short->id]['short'], $byPo[$short->id]['over']]);
        $this->assertSame([0, 12], [$byPo[$over->id]['short'], $byPo[$over->id]['over']]);

        // Supplier performance: 1 of 2 on time (the second was due yesterday), fill rate of finished POs.
        $perf = $r['suppliers'][0];
        $this->assertSame([2, 2, 1, 50.0, 0.5, 0.0, 3], [$perf['pos'], $perf['dated'], $perf['on_time'], $perf['on_time_percent'], $perf['avg_days_late'], $perf['lead_actual'], $perf['lead_set']]);
        // Both finished (closed short, received). Good stock: 96 of 120, and 60 of 60 (the 12 over don't fill anything).
        $this->assertSame('received', $over->fresh()->status->value);
        $this->assertSame(86.7, $perf['fill_percent']);
    }

    public function test_open_po_shows_outstanding_not_short_and_filters_apply()
    {
        $beans = Product::factory()->withPack('Carton', 12)->create();
        $other = Product::factory()->withPack('Carton', 12)->create();
        $po = $this->po($beans, 10, null);
        $this->receive($po, ['qty_accepted_base' => 60]);
        $this->receive($this->po($other, 1, null), ['qty_accepted_base' => 12]);

        $r = $this->report(['product_id' => $beans->id]);
        $this->assertSame([60, 0, 60], [$r['totals']['units'], $r['totals']['short'], $r['totals']['outstanding']]);
        $this->assertNull($r['suppliers'][0]['on_time_percent']); // no expected dates
        $this->assertNull($r['suppliers'][0]['fill_percent']); // nothing finished

        $this->assertSame(0, $this->report(['supplier_id' => Supplier::factory()->create()->id])['totals']['units']);
        $this->assertSame(0, $this->report(['from' => $this->day(-30), 'to' => $this->day(-8)])['totals']['units']);
    }

    public function test_page_and_exports_for_managers_only()
    {
        $this->receive($this->po(Product::factory()->withPack('Carton', 12)->create(), 2, null), ['qty_accepted_base' => 24]);

        $this->actingAs($this->manager)->get(route('reports.received.index', ['group' => 'po']))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('reports/insights/Received')->where('group', 'po')->where('report.totals.units', 24)->has('options.sites'));
        foreach (['csv', 'xlsx'] as $format) {
            $this->actingAs($this->manager)->get(route('reports.received.export', ['format' => $format, 'group' => 'supplier']))->assertOk()
                ->assertHeader('Content-Disposition');
        }
        $csv = $this->actingAs($this->manager)->get(route('reports.received.export', ['format' => 'csv']))->getContent();
        $this->assertStringContainsString('Supplier performance', $csv);

        $this->actingAs($this->userWithRole(Permissions::ROLE_WAREHOUSE_STAFF))->get(route('reports.received.index'))->assertForbidden();
        $this->actingAs($this->userWithRole(Permissions::ROLE_PURCHASING))->get(route('reports.received.index'))->assertForbidden();
    }
}
