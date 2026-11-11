<?php

namespace Tests\Feature\Reports;

use App\Authorization\Permissions;
use App\Enums\MovementType;
use App\Integrations\Ordermentum\OrderIngestor;
use App\Models\Order;
use App\Models\Product;
use App\Models\Range;
use App\Models\Setting;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Orders\PickListService;
use App\Services\Reports\DropReport;
use App\Services\Reports\StockValueReport;
use App\Services\Stock\MovementDraft;
use App\Support\BusinessDate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\Support\OrderFixtures;
use Tests\Support\OrdermentumFixtures;
use Tests\Support\StockFixtures;
use Tests\TestCase;

class MarginReportTest extends TestCase
{
    use OrderFixtures, OrdermentumFixtures, RefreshDatabase, StockFixtures;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedStockReferenceData();
        $this->manager = $this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER);
    }

    /** A product sold by the 6-pack: avg cost per each, default sell price per 6-pack. */
    private function sixPack(array $attrs = [], float $avgCost = 2.0, ?string $sell = '18.00'): Product
    {
        $p = Product::factory()->withPack('6-pack', 6)->create(['sell_price' => $sell] + $attrs);
        $p->units()->update(['default_sale' => false]);
        $p->units()->where('base_qty', 6)->update(['default_sale' => true]);
        $p->forceFill(['avg_cost' => $avgCost])->save();

        return $p->fresh();
    }

    private function report(string $basis = StockValueReport::ON_HAND, bool $sellable = false): array
    {
        return app(StockValueReport::class)->build($basis, $sellable);
    }

    private function row(array $report, Product $p): ?array
    {
        return collect($report['rows'])->firstWhere('id', $p->id);
    }

    public function test_stock_value_per_pack_with_gm_and_totals()
    {
        $p = $this->sixPack(['range_id' => Range::create(['name' => 'Juices'])->id]);
        $this->stockIn($p, 12); // 2 packs

        $r = $this->row($report = $this->report(), $p);

        $this->assertSame('6-pack', $r['pack']);
        $this->assertEquals(2, $r['packs']);
        $this->assertEquals(12.0, $r['cost_per_pack']);  // $2 each × 6
        $this->assertEquals(18.0, $r['sell_per_pack']);
        $this->assertSame('default', $r['sell_source']);
        $this->assertEquals(24.0, $r['value_cost']);
        $this->assertEquals(36.0, $r['value_sell']);
        $this->assertSame(33.33, $r['gm_percent']);
        $this->assertSame(BusinessDate::today(), $r['last_received']);
        $this->assertSame([], $r['missing']);

        $this->assertEquals(['value_cost' => 24.0, 'value_sell' => 36.0, 'gp' => 12.0, 'gm_percent' => 33.33, 'products' => 1, 'excluded' => 0], $report['totals']);
        $this->assertSame('Juices', $report['groups'][0]['range']);
    }

    public function test_products_missing_cost_or_sell_are_flagged_and_left_out_of_totals()
    {
        $good = $this->sixPack();
        $noCost = $this->sixPack(avgCost: 0);
        $noSell = $this->sixPack(sell: null);
        foreach ([$good, $noCost, $noSell] as $p) {
            $this->stockIn($p, 12);
        }

        $report = $this->report();

        $this->assertSame(['cost'], $this->row($report, $noCost)['missing']);
        $this->assertNull($this->row($report, $noCost)['value_cost']);
        $this->assertSame(['sell'], $this->row($report, $noSell)['missing']);
        $this->assertEquals(24.0, $this->row($report, $noSell)['value_cost'], 'Shown on its row, just not totalled.');
        $this->assertEquals(24.0, $report['totals']['value_cost']);
        $this->assertEquals(36.0, $report['totals']['value_sell']);
        $this->assertSame(2, $report['totals']['excluded']);
        $this->assertSame(['count' => 2, 'with_stock' => 2, 'no_cost' => 1, 'no_sell' => 1], $report['excluded']);
    }

    public function test_sell_price_falls_back_to_the_ordermentum_price_restated_for_the_pack()
    {
        $p = $this->sixPack(sell: null);
        $this->listing($p, 'var-1')->forceFill(['price' => '3.50'])->save(); // listed by the each
        $this->stockIn($p, 6);

        $r = $this->row($this->report(), $p);

        $this->assertEquals(21.0, $r['sell_per_pack']); // $3.50 × 6
        $this->assertSame('ordermentum', $r['sell_source']);
        $this->assertSame([], $r['missing']);
    }

    public function test_available_basis_and_sellable_only()
    {
        $p = $this->sixPack();
        $this->stockIn($p, 12);
        $this->manualOrder($this->manager, $p, 6); // reserves 6
        $notSold = $this->sixPack(['sell_channels' => 'none']);
        $this->stockIn($notSold, 6);

        $this->assertEquals(2, $this->row($this->report(), $p)['packs']);
        $this->assertEquals(1, $this->row($this->report(StockValueReport::AVAILABLE), $p)['packs']);
        $this->assertNotNull($this->row($this->report(), $notSold));
        $this->assertNull($this->row($this->report(sellable: true), $notSold));
    }

    public function test_reorder_suggestion_in_packs_when_at_or_below_the_point()
    {
        $p = $this->sixPack(['reorder_point' => 12, 'reorder_qty' => 20]);
        $this->stockIn($p, 12);
        $this->assertSame(4, $this->row($this->report(), $p)['reorder_packs']); // 20 each = 3.33 → 4 packs

        $this->stockIn($p, 1);
        $this->assertNull($this->row($this->report(), $p)['reorder_packs']);
    }

    public function test_modelled_drops_net_and_break_even()
    {
        $good = $this->sixPack(); // $18 sell, $12 cost a pack
        $thin = $this->sixPack(avgCost: 1.6, sell: '10.00'); // $10 sell, $9.60 cost
        $this->sixPack(avgCost: 0); // not shown

        $report = app(DropReport::class)->modelled(6, 30);
        $rows = collect($report['rows'])->keyBy('id');

        $this->assertEquals([108.0, 72.0, 36.0, 6.0, 5], [$rows[$good->id]['sales'], $rows[$good->id]['cost'], $rows[$good->id]['gp'], $rows[$good->id]['net'], $rows[$good->id]['break_even']]);
        $this->assertEquals(-27.6, $rows[$thin->id]['net']);
        $this->assertSame(75, $rows[$thin->id]['break_even']); // 30 / 0.40
        $this->assertCount(1, $report['missing']);
    }

    public function test_real_drops_use_cost_at_dispatch_and_flag_small_orders()
    {
        $p = $this->sixPack();
        $this->stockIn($p, 60);
        $order = $this->manualOrder($this->manager, $p, 12); // 12 each = 2 six-packs
        $order->lines()->update(['unit_price' => null]);
        // The line is in eaches with no price: the default price restated per each ($3) is used.
        $this->dispatch($order);
        $order->forceFill(['delivery_date' => '2026-11-10'])->save();

        $this->assertEquals(2.0, (float) StockMovement::where('type', MovementType::Sale)->sole()->unit_cost);
        $p->forceFill(['avg_cost' => 5])->save(); // a later receipt moves the average

        $r = app(DropReport::class)->real('2026-11-01', '2026-11-30', 30, 250);
        $o = $r['orders'][0];

        $this->assertEquals([36.0, 24.0, 12.0, -18.0], [$o['sales'], $o['cost'], $o['gp'], $o['net']]);
        $this->assertSame('dispatch', $o['cost_basis']);
        $this->assertTrue($o['price_estimated']);
        $this->assertTrue($o['under_minimum']);
        $this->assertSame('Not on a run', $o['area']);
        $this->assertEquals(['area' => 'Not on a run', 'drops' => 1, 'sales' => 36.0, 'cost' => 24.0, 'gp' => 12.0, 'delivery_cost' => 30.0, 'net' => -18.0, 'avg_net' => -18.0, 'under_minimum' => 1], $r['areas'][0]);

        $this->assertSame([], app(DropReport::class)->real('2026-12-01', '2026-12-31', 30, 250)['orders']);
    }

    public function test_real_drops_fall_back_to_current_cost_for_sales_posted_without_one()
    {
        $p = $this->sixPack(avgCost: 3);
        $this->stockIn($p, 60);
        $order = $this->manualOrder($this->manager, $p, 120, allowBackorder: true);
        $order->lines()->update(['unit_price' => '4.00']);
        // A sale from before dispatch recorded its cost.
        $this->ledger()->postOne(new MovementDraft(product: $p, location: $this->bin(), qty: -60, type: MovementType::Sale,
            idempotencyKey: 'old-sale', sourceType: 'order', sourceId: $order->id));
        $order->lines()->update(['qty_dispatched_base' => 60]);
        $order->forceFill(['status' => 'partially_dispatched', 'delivery_date' => '2026-11-10'])->save();

        $o = app(DropReport::class)->real('2026-11-10', '2026-11-10', 30, 250)['orders'][0];

        $this->assertEquals([240.0, 180.0, 60.0, 30.0], [$o['sales'], $o['cost'], $o['gp'], $o['net']]);
        $this->assertSame('current', $o['cost_basis']);
        $this->assertFalse($o['price_estimated']);
        $this->assertTrue($o['under_minimum']); // $240 < $250
    }

    public function test_ordermentum_orders_keep_the_listing_price_current()
    {
        $this->configureOrdermentum(['push' => false]);
        $p = $this->sixPack(sell: null);
        $listing = $this->listing($p, 'var-1', unitBaseQty: 6);
        $this->stockIn($p, 12);
        Http::fake(['api.om.test/v1/orders/o-1*' => Http::response(['id' => 'o-1', 'number' => 'OM1', 'status' => 'Placed', 'retailerId' => 'r-1',
            'updatedAt' => '2026-11-02T01:00:00.000Z', 'deliveryDate' => '2026-11-03T00:00:00.000Z',
            'lineItems' => [['variantId' => 'var-1', 'quantity' => 1, 'price' => '19.50']]])]);

        app(OrderIngestor::class)->ingest('o-1');

        $this->assertEquals(19.5, (float) $listing->fresh()->price);
        $this->assertEquals(19.5, $this->row($this->report(), $p)['sell_per_pack']);
    }

    public function test_page_is_for_managers_and_admins_only()
    {
        $this->actingAs($this->userWithRole(Permissions::ROLE_READ_ONLY))->get(route('reports.margin.index'))->assertForbidden();
        $this->actingAs($this->userWithRole(Permissions::ROLE_WAREHOUSE_STAFF))->get(route('reports.margin.index'))->assertForbidden();
        $this->actingAs($this->userWithRole(Permissions::ROLE_PURCHASING))->get(route('reports.margin.export', ['stock', 'csv']))->assertForbidden();

        $this->sixPack();
        $this->actingAs($this->manager)->get(route('reports.margin.index', ['basis' => 'available', 'units' => 8]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('reports/Margin')
                ->where('filters.basis', 'available')->where('filters.units', 8)
                ->where('settings.delivery_cost', 30)->has('stock.groups', 1)->has('modelled.rows', 1)->has('real.orders', 0));
    }

    public function test_delivery_settings_are_saved_as_company_settings()
    {
        $this->actingAs($this->manager)->put(route('reports.margin.settings'), ['delivery_cost' => '35.5', 'free_delivery_min' => 300])->assertSessionHasNoErrors();

        $this->assertSame('35.50', Setting::get(Setting::DROP_DELIVERY_COST));
        $this->assertSame(300.0, Setting::freeDeliveryMin());
        $this->actingAs($this->manager)->put(route('reports.margin.settings'), ['delivery_cost' => -1, 'free_delivery_min' => 300])->assertSessionHasErrors('delivery_cost');
    }

    public function test_exports_both_views_as_xlsx_and_csv()
    {
        $p = $this->sixPack();
        $this->stockIn($p, 12);
        $this->sixPack(avgCost: 0);

        $csv = $this->actingAs($this->manager)->get(route('reports.margin.export', ['stock', 'csv']))->assertOk()->getContent();
        $this->assertStringContainsString($p->sku, $csv);
        $this->assertStringContainsString('No cost', $csv);
        $this->assertStringContainsString('left out of the totals', $csv);

        $drops = $this->actingAs($this->manager)->get(route('reports.margin.export', ['drops', 'csv', 'units' => 6]))->assertOk()->getContent();
        $this->assertStringContainsString('108', $drops);

        foreach (['stock', 'drops', 'real'] as $view) {
            $res = $this->actingAs($this->manager)->get(route('reports.margin.export', [$view, 'xlsx']))->assertOk();
            $this->assertStringStartsWith('PK', $res->getContent());
            $this->assertStringContainsString('spreadsheetml', $res->headers->get('Content-Type'));
        }
    }

    private function dispatch(Order $order): void
    {
        $picks = app(PickListService::class);
        $list = $picks->create($this->manager, $this->site(), [$order->id])['pickList'];
        $picks->recordPicked($list, $list->lines()->pluck('qty_to_pick_base', 'id')->all());
        $picks->dispatch($list->fresh(), $this->manager);
    }
}
