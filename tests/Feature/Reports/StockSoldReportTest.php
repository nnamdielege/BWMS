<?php

namespace Tests\Feature\Reports;

use App\Authorization\Permissions;
use App\Enums\OrderChannel;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Range;
use App\Models\User;
use App\Services\Reports\Insights\InsightFilters;
use App\Services\Reports\Insights\StockSoldReport;
use App\Services\Reports\PackPricing;
use App\Support\BusinessDate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\Support\OrderFixtures;
use Tests\Support\StockFixtures;
use Tests\TestCase;

class StockSoldReportTest extends TestCase
{
    use OrderFixtures, RefreshDatabase, StockFixtures;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedStockReferenceData();
        $this->manager = $this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER);
    }

    private function product(float $cost, array $attrs = []): Product
    {
        $p = Product::factory()->create($attrs);
        $p->forceFill(['avg_cost' => $cost])->save();
        $this->stockIn($p, 1000);

        return $p->fresh();
    }

    private function report(int $days = 7, array $filters = []): array
    {
        $to = BusinessDate::today();

        return app(StockSoldReport::class)->build(new InsightFilters(Carbon::parse($to)->subDays($days - 1)->toDateString(), $to,
            rangeId: $filters['range_id'] ?? null, productId: $filters['product_id'] ?? null));
    }

    public function test_sales_cost_and_gp_by_sku_range_customer_and_channel()
    {
        $dairy = Range::create(['name' => 'Dairy']);
        $milk = $this->product(2.0, ['range_id' => $dairy->id]);
        $bread = $this->product(1.0);
        $cafe = Customer::factory()->create(['name' => 'Cafe']);
        $this->dispatchedSale($this->manager, $milk, 10, $cafe, '5.00');
        $this->dispatchedSale($this->manager, $bread, 4, $cafe, '1.50', OrderChannel::Portal);
        $this->dispatchedSale($this->manager, $milk, 3, null, null, OrderChannel::Ordermentum); // no price: default price
        // Later cost changes don't move what the dispatch cost.
        $milk->forceFill(['avg_cost' => 9])->save();

        $r = $this->report();
        $defaultPrice = PackPricing::sellFor($milk, 1) ?? 0.0;
        $sales = round(50 + 6 + 3 * $defaultPrice, 2);
        $cost = 20 + 4 + 6.0;
        $this->assertSame([17, $sales, $cost, round($sales - $cost, 2), 3, 2, 1],
            [$r['totals']['units'], $r['totals']['sales'], $r['totals']['cost'], $r['totals']['gp'], $r['totals']['orders'], $r['totals']['customers'], $r['totals']['estimated']]);

        $sku = collect($r['groups']['sku'])->keyBy('product_id');
        $this->assertSame([13, 26.0], [$sku[$milk->id]['units'], $sku[$milk->id]['cost']]);
        $this->assertSame([6.0, 4.0, 2.0, 33.33], [$sku[$bread->id]['sales'], $sku[$bread->id]['cost'], $sku[$bread->id]['gp'], $sku[$bread->id]['gm_percent']]);
        $this->assertEqualsCanonicalizing(['Dairy', 'No range'], array_column($r['groups']['range'], 'label'));
        $this->assertEqualsCanonicalizing(['Manual', 'Portal', 'Ordermentum'], array_column($r['groups']['channel'], 'label'));
        $this->assertSame(56.0, collect($r['groups']['customer'])->firstWhere('label', 'Cafe')['sales']);

        $this->assertSame($bread->id, $r['top']['products_bottom'][0]['product_id']); // least GP
        $this->assertSame([13], array_column($this->report(7, ['range_id' => $dairy->id])['groups']['sku'], 'units'));
    }

    public function test_week_on_week_trend()
    {
        $milk = $this->product(2.0);
        $this->travelTo(now()->subWeek());
        $this->dispatchedSale($this->manager, $milk, 10, null, '5.00');
        $this->travelBack();
        $this->dispatchedSale($this->manager, $milk, 15, null, '5.00');

        $trend = collect($this->report(14)['trend'])->filter(fn ($w) => $w['sales'] > 0)->values();
        $this->assertSame([50.0, 75.0], $trend->pluck('sales')->all());
        $this->assertSame(50.0, $trend[1]['change_percent']);
    }

    public function test_customers_who_stopped_ordering_a_regular_product()
    {
        $milk = $this->product(2.0);
        $bread = $this->product(1.0);
        $gone = Customer::factory()->create(['name' => 'Gone off milk']);
        $steady = Customer::factory()->create(['name' => 'Steady']);
        foreach ([90, 80, 70] as $daysAgo) {
            $this->travelTo(now()->subDays($daysAgo));
            $this->dispatchedSale($this->manager, $milk, 2, $gone, '5.00');
            $this->travelBack();
        }
        foreach ([30, 20, 10] as $daysAgo) {
            $this->travelTo(now()->subDays($daysAgo));
            $this->dispatchedSale($this->manager, $milk, 2, $steady, '5.00');
            $this->travelBack();
        }
        $this->dispatchedSale($this->manager, $bread, 1, $gone, '2.00'); // still a customer

        $lapsed = $this->report()['lapsed'];
        $this->assertCount(1, $lapsed);
        $this->assertSame(['Gone off milk', $milk->id, 3, 10.0, 70, true, 10.0],
            [$lapsed[0]['customer'], $lapsed[0]['product_id'], $lapsed[0]['orders'], $lapsed[0]['usual_gap_days'], $lapsed[0]['days_since'], $lapsed[0]['still_ordering'], $lapsed[0]['avg_order_sales']]);
    }

    public function test_page_and_exports_for_managers_only()
    {
        $this->dispatchedSale($this->manager, $this->product(2.0), 5, null, '3.00');

        $this->actingAs($this->manager)->get(route('reports.sold.index', ['group' => 'channel']))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('reports/insights/Sold')->where('report.totals.units', 5)->has('report.groups.channel', 1));
        foreach (['csv', 'xlsx'] as $format) {
            $this->actingAs($this->manager)->get(route('reports.sold.export', ['format' => $format, 'group' => 'customer']))->assertOk();
        }
        $this->assertStringContainsString('Week on week', $this->actingAs($this->manager)->get(route('reports.sold.export', ['format' => 'csv']))->getContent());
        $this->actingAs($this->userWithRole(Permissions::ROLE_READ_ONLY))->get(route('reports.sold.index'))->assertForbidden();
    }
}
