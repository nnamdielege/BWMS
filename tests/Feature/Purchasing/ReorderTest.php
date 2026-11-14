<?php

namespace Tests\Feature\Purchasing;

use App\Authorization\Permissions;
use App\Enums\PurchaseOrderStatus;
use App\Mail\ReorderSummaryMail;
use App\Models\Customer;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\SalesHistory;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Orders\OrderService;
use App\Services\Orders\PickListService;
use App\Services\Purchasing\PurchaseOrderService;
use App\Services\Purchasing\ReorderPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\Support\StockFixtures;
use Tests\TestCase;

/** Purchasing → Reorder suggestions: the planner, draft POs, the dashboard tile and the 7am email. */
class ReorderTest extends TestCase
{
    use RefreshDatabase, StockFixtures;

    private User $manager;

    private Supplier $supplier;

    private Product $milk;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-16 09:00', 'Australia/Brisbane')); // Monday
        $this->seedStockReferenceData();
        $this->manager = $this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER);
        $this->supplier = Supplier::factory()->create(['name' => 'Dairy Co', 'lead_time_days' => 5, 'review_period_days' => 7, 'min_order_value' => 100]);
        // Bought by the carton of 6; sold by the each.
        $this->milk = Product::factory()->withPack('Carton', 6)->create(['sku' => 'MILK', 'supplier_id' => $this->supplier->id, 'order_multiple_base' => 6]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** $perDay sold every day of the 8-week window, as imported history. */
    private function history(Product $p, int $perDay, int $days = 56): void
    {
        for ($i = 1; $i <= $days; $i++) {
            SalesHistory::create(['source' => SalesHistory::SOURCE_ORDERMENTUM, 'product_id' => $p->id, 'sale_date' => Carbon::parse('2026-11-16')->subDays($i)->toDateString(),
                'qty_base' => $perDay, 'external_ref' => "om-{$p->id}-{$i}", 'line_ref' => '1']);
        }
    }

    /** A sent PO for $cartons cartons at $12 a carton. */
    private function sentPo(Product $p, int $cartons): PurchaseOrder
    {
        $service = app(PurchaseOrderService::class);
        $po = $service->saveDraft(null, ['supplier_id' => $this->supplier->id, 'site_id' => $this->site()->id, 'order_date' => '2026-11-10',
            'lines' => [['product_id' => $p->id, 'product_unit_id' => $p->units()->where('base_qty', 6)->value('id'), 'qty_ordered' => $cartons, 'unit_cost' => '12']]], $this->manager);

        return $service->markSent($po, $this->manager);
    }

    private function line(array $plan, Product $p): ?array
    {
        return collect($plan['groups'])->flatMap(fn ($g) => $g['lines'])->firstWhere('product_id', $p->id);
    }

    public function test_the_suggestion_covers_lead_time_and_review_less_stock_and_open_pos_rounded_to_the_pack()
    {
        $this->history($this->milk, 2);
        $this->stockIn($this->milk, 10);
        $this->sentPo($this->milk, 1); // 6 on order

        $row = $this->line(app(ReorderPlanner::class)->build(), $this->milk);

        $this->assertSame(2.0, $row['avg_daily']);
        $this->assertSame(12, $row['cover_days']); // 5 lead + 7 review
        $this->assertSame(6.0, $row['safety']); // 3 days × 2
        $this->assertSame(10, $row['available']);
        $this->assertSame(6, $row['on_order']);
        $this->assertSame(14.0, $row['need']); // 2 × 12 + 6 − (10 + 6)
        $this->assertSame(18, $row['suggest_base']); // up to 3 cartons
        $this->assertSame(3, $row['suggest_packs']);
        $this->assertSame(36.0, $row['suggest_value']); // at the last PO's $12 a carton
        $this->assertSame(5.0, $row['days_left']);
        $this->assertSame('2026-11-21', $row['stockout_date']);
        $this->assertSame('2026-11-21', $row['next_delivery']);
        $this->assertFalse($row['stockout_before_delivery']);
        $this->assertSame(56, $row['sold_4w']);
        $this->assertSame(['ordermentum_history' => 112], $row['sources']);
        $this->assertStringContainsString('Need: 2 × 12 + 6 − (10 available + 6 on order) = 14', implode("\n", $row['working']));
    }

    public function test_supplier_moq_and_order_days_apply()
    {
        $this->supplier->update(['order_days' => '1,4', 'moq_packs' => 5]); // Mon/Thu: today is Monday, next Thursday
        $this->history($this->milk, 1);
        $this->stockIn($this->milk, 10);

        $row = $this->line(app(ReorderPlanner::class)->build(), $this->milk);

        $this->assertSame(3, $row['review_days']);
        $this->assertSame(1.0, $row['need']); // 1 × 8 + 3 − 10
        $this->assertSame(30, $row['suggest_base']); // 1 carton → MOQ 5 cartons
    }

    public function test_bwms_dispatches_count_as_demand_and_inactive_or_unsold_products_are_left_out()
    {
        $customer = Customer::factory()->create();
        $this->stockIn($this->milk, 20);
        Carbon::setTestNow(Carbon::parse('2026-11-14 10:00', 'Australia/Brisbane'));
        $order = app(OrderService::class)->createManual($this->manager, ['customer_id' => $customer->id, 'site_id' => $this->site()->id, 'short_ship_policy' => 'backorder',
            'lines' => [['product_id' => $this->milk->id, 'product_unit_id' => $this->milk->units()->where('base_qty', 1)->value('id'), 'qty' => 7]]]);
        $picks = app(PickListService::class);
        $list = $picks->create($this->manager, $this->site(), [$order->id])['pickList'];
        $picks->recordPicked($list, $list->lines()->pluck('qty_to_pick_base', 'id')->all());
        $picks->dispatch($list, $this->manager);
        Carbon::setTestNow(Carbon::parse('2026-11-16 09:00', 'Australia/Brisbane'));
        Product::factory()->create(['sku' => 'OLD', 'is_active' => false]);
        Product::factory()->create(['sku' => 'NOTSOLD', 'sell_channels' => 'none']);

        $plan = app(ReorderPlanner::class)->build(['only_needed' => false]);

        $this->assertSame(['bwms' => 7], $this->line($plan, $this->milk)['sources']);
        $skus = collect($plan['groups'])->flatMap(fn ($g) => $g['lines'])->pluck('sku')->all();
        $this->assertNotContains('OLD', $skus);
        $this->assertNotContains('NOTSOLD', $skus);
    }

    public function test_the_page_lists_suppliers_and_filters()
    {
        $this->history($this->milk, 2);
        $other = Product::factory()->create(['sku' => 'TEA', 'supplier_id' => $this->supplier->id]);
        $this->stockIn($other, 50);

        $this->actingAs($this->manager)->get(route('reorder.index'))->assertInertia(fn (AssertableInertia $p) => $p
            ->component('purchasing/Reorder')
            ->where('plan.groups.0.supplier.name', 'Dairy Co')
            ->has('plan.groups.0.lines', 1) // only items that need ordering, by default
            ->where('plan.groups.0.lines.0.sku', 'MILK')
            ->where('plan.groups.0.meets_min', false) // 4 cartons at avg cost 0 < $100
            ->where('can.order', true));
        $this->actingAs($this->manager)->get(route('reorder.index', ['only_needed' => 0]))
            ->assertInertia(fn (AssertableInertia $p) => $p->has('plan.groups.0.lines', 2));
        $this->actingAs($this->manager)->get(route('reorder.index', ['stockout_only' => 1, 'only_needed' => 0]))
            ->assertInertia(fn (AssertableInertia $p) => $p->has('plan.groups.0.lines', 1)->where('plan.groups.0.lines.0.sku', 'MILK'));
        $this->actingAs($this->userWithRole(Permissions::ROLE_READ_ONLY))->get(route('reorder.index'))
            ->assertInertia(fn (AssertableInertia $p) => $p->where('can.order', false));
    }

    public function test_create_draft_po_uses_the_purchase_pack_sends_nothing_and_takes_the_suggestion_off()
    {
        Mail::fake();
        $this->history($this->milk, 2);
        $this->stockIn($this->milk, 10);
        $this->sentPo($this->milk, 1)->update(['status' => PurchaseOrderStatus::Received]); // a past order: $12 a carton, nothing outstanding

        $res = $this->actingAs($this->manager)->post(route('reorder.draft'), ['supplier_id' => $this->supplier->id, 'lines' => [['product_id' => $this->milk->id, 'qty_base' => 20]]]);

        $po = PurchaseOrder::where('status', PurchaseOrderStatus::Draft)->sole();
        $res->assertRedirect(route('purchase-orders.edit', $po));
        $line = $po->lines()->sole();
        $this->assertSame('Carton', $line->unit->name);
        $this->assertSame(4, $line->qty_ordered); // 20 each → 4 cartons, rounded up
        $this->assertSame('12.0000', $line->unit_cost);
        $this->assertSame('2026-11-21', $po->expected_date->toDateString());
        Mail::assertNothingSent();

        $this->assertSame(0, $this->line(app(ReorderPlanner::class)->build(['only_needed' => false]), $this->milk)['suggest_base'], 'the draft counts as on order');
        $this->actingAs($this->userWithRole(Permissions::ROLE_READ_ONLY))
            ->post(route('reorder.draft'), ['supplier_id' => $this->supplier->id, 'lines' => [['product_id' => $this->milk->id, 'qty_base' => 6]]])->assertForbidden();
        $this->actingAs($this->manager)->post(route('reorder.draft'), ['supplier_id' => $this->supplier->id, 'lines' => [['product_id' => $this->milk->id, 'qty_base' => 0]]])
            ->assertSessionHasErrors('reorder');
    }

    public function test_dashboard_counts_products_that_run_out_before_the_next_delivery()
    {
        $this->history($this->milk, 2);
        $this->stockIn($this->milk, 4); // 2 days of stock, 5 days to a delivery

        $this->actingAs($this->manager)->get(route('dashboard'))->assertInertia(fn (AssertableInertia $p) => $p->where('widgets.stockout_risk', 1));
    }

    public function test_the_7am_email_goes_to_managers_only_when_switched_on()
    {
        Mail::fake();
        $this->history($this->milk, 2);
        $this->stockIn($this->milk, 4);
        $admin = $this->userWithRole(Permissions::ROLE_ADMIN);
        $staff = $this->userWithRole(Permissions::ROLE_WAREHOUSE_STAFF);

        $this->artisan('reorder:daily-email')->assertSuccessful();
        Mail::assertNothingSent();

        $this->actingAs($this->manager)->put(route('reorder.settings'), ['safety_mode' => 'days', 'safety_days' => 3, 'safety_z' => 1.65, 'default_lead_days' => 7, 'default_review_days' => 7, 'daily_email' => true])
            ->assertSessionHasNoErrors();
        $this->assertTrue(Setting::reorder()['daily_email']);
        $this->artisan('reorder:daily-email')->assertSuccessful();

        Mail::assertSent(ReorderSummaryMail::class, 2);
        Mail::assertSent(ReorderSummaryMail::class, fn ($m) => $m->hasTo($this->manager->email) && $m->stockouts === 1 && $m->suppliers[0]['supplier'] === 'Dairy Co');
        Mail::assertSent(ReorderSummaryMail::class, fn ($m) => $m->hasTo($admin->email));
        Mail::assertNotSent(ReorderSummaryMail::class, fn ($m) => $m->hasTo($staff->email));
        $this->assertStringContainsString('Dairy Co', (new ReorderSummaryMail([['supplier' => 'Dairy Co', 'to_order' => 1, 'value' => 36.0, 'min' => 100.0, 'meets_min' => false, 'stockouts' => ['MILK']]], 1, '2026-11-16'))->render());
    }

    public function test_supplier_and_product_forms_save_the_reorder_fields()
    {
        $this->actingAs($this->manager)->put(route('suppliers.update', $this->supplier), [
            'code' => $this->supplier->code, 'name' => 'Dairy Co', 'lead_time_days' => 4, 'order_days' => [4, 1], 'review_period_days' => null, 'min_order_value' => 150, 'moq_packs' => 2, 'is_active' => true,
        ])->assertSessionHasNoErrors();
        $this->supplier->refresh();
        $this->assertSame('1,4', $this->supplier->order_days);
        $this->assertSame([1, 4], $this->supplier->orderDays());
        $this->assertSame('150.00', $this->supplier->min_order_value);
        $this->assertSame(2, $this->supplier->moq_packs);

        $units = $this->milk->units()->get()->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'base_qty' => $u->base_qty, 'purchasable' => (bool) $u->purchasable,
            'sellable' => (bool) $u->sellable, 'default_purchase' => (bool) $u->default_purchase, 'default_sale' => (bool) $u->default_sale, 'barcodes' => []])->all();
        $this->actingAs($this->manager)->put(route('products.update', $this->milk), [
            'sku' => 'MILK', 'name' => $this->milk->name, 'base_unit' => 'each', 'units' => $units, 'is_active' => true,
            'supplier_id' => $this->supplier->id, 'lead_time_days' => 2, 'order_multiple_base' => 12,
        ])->assertSessionHasNoErrors();
        $this->milk->refresh();
        $this->assertSame([$this->supplier->id, 2, 12], [$this->milk->supplier_id, $this->milk->lead_time_days, $this->milk->order_multiple_base]);
        $this->actingAs($this->manager)->get(route('products.edit', $this->milk))->assertInertia(fn (AssertableInertia $p) => $p->has('suppliers', 1));
    }
}
