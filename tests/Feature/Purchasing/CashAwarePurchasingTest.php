<?php

namespace Tests\Feature\Purchasing;

use App\Authorization\Permissions;
use App\Enums\PurchaseOrderStatus;
use App\Models\Customer;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchasingBudget;
use App\Models\SalesHistory;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Orders\OrderService;
use App\Services\Orders\PickListService;
use App\Services\Purchasing\CashOutReport;
use App\Services\Purchasing\OverBudget;
use App\Services\Purchasing\PurchaseOrderService;
use App\Services\Purchasing\PurchasingBudgetService;
use App\Services\Purchasing\ReorderPlanner;
use App\Services\Purchasing\ReorderService;
use App\Services\Purchasing\SlowMoversReport;
use App\Services\Stock\Exceptions\InvalidMovement;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Support\StockFixtures;
use Tests\TestCase;

/** Cash-aware purchasing: one velocity, suggested POs, approval against the weekly budget, cash out, slow movers. */
class CashAwarePurchasingTest extends TestCase
{
    use RefreshDatabase, StockFixtures;

    private User $manager;

    private Supplier $dairy;

    private Product $milk;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-16 09:00', 'Australia/Brisbane')); // Monday
        $this->seedStockReferenceData();
        $this->manager = $this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER);
        $this->dairy = Supplier::factory()->create(['name' => 'Dairy Co', 'lead_time_days' => 5, 'review_period_days' => 7, 'min_order_value' => 100, 'payment_terms_days' => 30]);
        // Bought by the carton of 6, sold by the each at $3.
        $this->milk = Product::factory()->withPack('Carton', 6)->create(['sku' => 'MILK', 'supplier_id' => $this->dairy->id, 'order_multiple_base' => 6, 'sell_price' => 3]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function history(Product $p, int $perDay, int $days = 56): void
    {
        for ($i = 1; $i <= $days; $i++) {
            SalesHistory::create(['source' => SalesHistory::SOURCE_ORDERMENTUM, 'product_id' => $p->id, 'sale_date' => Carbon::parse('2026-11-16')->subDays($i)->toDateString(),
                'qty_base' => $perDay, 'external_ref' => "om-{$p->id}-{$i}", 'line_ref' => '1']);
        }
    }

    /** A draft PO for milk cartons at $cost a carton (GST-free unless $tax is given). */
    private function draft(int $cartons, string $cost = '12', ?string $expected = null, int $tax = 0): PurchaseOrder
    {
        return app(PurchaseOrderService::class)->saveDraft(null, [
            'supplier_id' => $this->dairy->id, 'site_id' => $this->site()->id, 'order_date' => '2026-11-16', 'expected_date' => $expected,
            'lines' => [['product_id' => $this->milk->id, 'product_unit_id' => $this->milk->units()->where('base_qty', 6)->value('id'), 'qty_ordered' => $cartons, 'unit_cost' => $cost, 'tax_rate' => $tax]],
        ], $this->manager);
    }

    private function budget(float $amount, string $week = '2026-11-16'): void
    {
        PurchasingBudget::create(['week_start' => $week, 'budget' => $amount]);
    }

    /** Dispatched last Saturday: 7 each at $2.50 = $17.50, so this week's estimate is 74% = $12.95. */
    private function dispatchedLastSaturday(): void
    {
        $this->stockIn($this->milk, 20);
        Carbon::setTestNow(Carbon::parse('2026-11-14 10:00', 'Australia/Brisbane'));
        $order = app(OrderService::class)->createManual($this->manager, ['customer_id' => Customer::factory()->create()->id, 'site_id' => $this->site()->id, 'short_ship_policy' => 'backorder',
            'lines' => [['product_id' => $this->milk->id, 'product_unit_id' => $this->milk->units()->where('base_qty', 1)->value('id'), 'qty' => 7, 'unit_price' => '2.50']]]);
        $picks = app(PickListService::class);
        $list = $picks->create($this->manager, $this->site(), [$order->id])['pickList'];
        $picks->recordPicked($list, $list->lines()->pluck('qty_to_pick_base', 'id')->all());
        $picks->dispatch($list, $this->manager);
        Carbon::setTestNow(Carbon::parse('2026-11-16 09:00', 'Australia/Brisbane'));
    }

    private function line(Product $p): array
    {
        return collect(app(ReorderPlanner::class)->build(['only_needed' => false])['groups'])->flatMap(fn ($g) => $g['lines'])->firstWhere('product_id', $p->id);
    }

    // --- Velocity: one figure, from the planner -----------------------------------------------------------------

    public function test_daily_velocity_is_the_reorder_pages_figure()
    {
        $this->history($this->milk, 2);
        $quiet = Product::factory()->create();

        $v = app(ReorderPlanner::class)->dailyVelocity([$this->milk->id, $quiet->id]);

        $this->assertSame(2.0, $v[$this->milk->id]);
        $this->assertSame(0.0, $v[$quiet->id]);
        $this->assertSame($this->line($this->milk)['avg_daily'], round($v[$this->milk->id], 3));
    }

    public function test_daily_velocity_weights_recent_weeks_and_leaves_out_stock_out_days()
    {
        // 7 a day in the last week only: (7 × 8) / (8+7+…+1) = 56 / 36.
        $this->history($this->milk, 7, 7);
        $this->assertEqualsWithDelta(56 / 36, app(ReorderPlanner::class)->dailyVelocity([$this->milk->id])[$this->milk->id], 0.0001);
    }

    // --- Suggested quantity --------------------------------------------------------------------------------------

    public function test_suggested_qty_rounds_up_to_the_order_multiple_and_uses_the_products_safety_days()
    {
        $this->history($this->milk, 2);
        $this->stockIn($this->milk, 10);

        // Setting: 3 safety days. 2 × 12 + 6 − 10 = 20 → 4 cartons.
        $this->assertSame(24, $this->line($this->milk)['suggest_base']);

        // Product override 7 days: 2 × 12 + 14 − 10 = 28 → 5 cartons.
        $this->milk->update(['safety_stock_days' => 7]);
        $row = $this->line($this->milk);
        $this->assertSame(14.0, $row['safety']);
        $this->assertSame(30, $row['suggest_base']);
        $this->assertSame(3.0, Setting::reorder()['safety_days'], 'the global setting is unchanged');
    }

    public function test_suggested_qty_subtracts_approved_and_suggested_pos_and_is_zero_when_stock_covers_it()
    {
        $this->history($this->milk, 2);
        $this->stockIn($this->milk, 10);
        $po = app(PurchaseOrderService::class)->approve($this->draft(2), $this->manager, true, 'test');
        $this->assertSame(PurchaseOrderStatus::Approved, $po->status);

        $row = $this->line($this->milk);
        $this->assertSame(12, $row['on_order']);
        $this->assertSame(12, $row['suggest_base']); // 2 × 12 + 6 − (10 + 12) = 8 → 2 cartons

        $this->stockIn($this->milk, 100);
        $this->assertSame(0, $this->line($this->milk)['suggest_base']);
    }

    // --- Suggested POs -------------------------------------------------------------------------------------------

    public function test_draft_purchase_orders_makes_one_suggested_po_per_supplier()
    {
        $bakery = Supplier::factory()->create(['name' => 'Bakery', 'lead_time_days' => 2, 'review_period_days' => 7]);
        $bread = Product::factory()->create(['sku' => 'BREAD', 'supplier_id' => $bakery->id]);
        $cream = Product::factory()->create(['sku' => 'CREAM', 'supplier_id' => $this->dairy->id, 'auto_reorder' => false]);
        $this->history($this->milk, 2);
        $this->history($bread, 1);
        $this->history($cream, 5);
        $this->stockIn($this->milk, 10);

        $results = app(ReorderService::class)->draftPurchaseOrders();

        $this->assertSame(['created', 'created'], $results->pluck('action')->all());
        $this->assertSame(2, PurchaseOrder::where('status', PurchaseOrderStatus::Suggested)->count());

        $dairyPo = PurchaseOrder::where('supplier_id', $this->dairy->id)->sole();
        $this->assertSame(['MILK'], $dairyPo->lines->map(fn ($l) => $l->product->sku)->all(), 'CREAM is not on auto reorder');
        $this->assertSame(4, $dairyPo->lines->first()->qty_ordered); // cartons
        $this->assertSame('2026-11-21', $dairyPo->expected_date->toDateString()); // today + 5 days lead
        $this->assertNull($dairyPo->payment_due_date, 'set on approval');
        $this->assertTrue($results->firstWhere('po.id', $dairyPo->id)['below_minimum'], 'flagged under the $100 minimum, but still made');

        $breadPo = PurchaseOrder::where('supplier_id', $bakery->id)->sole();
        $this->assertSame(12, $breadPo->lines->first()->qty_ordered_base); // 1 × 9 + 3
        $this->assertSame('2026-11-18', $breadPo->expected_date->toDateString());
        $this->assertFalse($results->firstWhere('po.id', $breadPo->id)['below_minimum']);
    }

    public function test_draft_purchase_orders_is_idempotent_and_leaves_edited_pos_alone()
    {
        $this->history($this->milk, 2);
        $this->stockIn($this->milk, 10);
        $reorder = app(ReorderService::class);

        $first = $reorder->draftPurchaseOrders()->first()['po'];
        $again = $reorder->draftPurchaseOrders();

        $this->assertSame('updated', $again->first()['action']);
        $this->assertSame($first->id, $again->first()['po']->id);
        $this->assertSame(1, PurchaseOrder::count());
        $this->assertSame(4, $first->fresh()->lines->first()->qty_ordered, 'its own quantity is not counted as on order');

        // Edited by a person, it becomes a draft and counts as on order: nothing new is suggested.
        $this->actingAs($this->manager)->put(route('purchase-orders.update', $first), [
            'supplier_id' => $this->dairy->id, 'site_id' => $this->site()->id, 'order_date' => '2026-11-16',
            'lines' => [['product_id' => $this->milk->id, 'product_unit_id' => $this->milk->units()->where('base_qty', 6)->value('id'), 'qty_ordered' => 4, 'unit_cost' => '12']],
        ])->assertSessionHasNoErrors();
        $this->assertSame(PurchaseOrderStatus::Draft, $first->fresh()->status);
        $this->assertTrue($reorder->draftPurchaseOrders()->isEmpty());
        $this->assertSame(1, PurchaseOrder::count());
    }

    public function test_a_suggestion_no_longer_needed_is_cancelled()
    {
        $this->history($this->milk, 2);
        $this->stockIn($this->milk, 10);
        $reorder = app(ReorderService::class);
        $po = $reorder->draftPurchaseOrders()->first()['po'];

        $this->stockIn($this->milk, 100);
        $results = $reorder->draftPurchaseOrders();

        $this->assertSame('cancelled', $results->first()['action']);
        $this->assertSame(PurchaseOrderStatus::Cancelled, $po->fresh()->status);
    }

    public function test_the_command_and_the_button_generate_suggestions_and_the_command_runs_mondays_at_6()
    {
        $this->history($this->milk, 2);

        $this->artisan('purchasing:suggest')->expectsOutputToContain('created')->assertSuccessful();
        $this->assertSame(1, PurchaseOrder::where('status', PurchaseOrderStatus::Suggested)->count());

        $this->actingAs($this->manager)->post(route('purchase-orders.suggest'))->assertSessionHasNoErrors()->assertRedirect(route('purchase-orders.index', ['status' => 'suggested']));
        $this->assertSame(1, PurchaseOrder::count());
        $this->actingAs($this->userWithRole(Permissions::ROLE_READ_ONLY))->post(route('purchase-orders.suggest'))->assertForbidden();

        Artisan::call('schedule:list'); // loads routes/console.php
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'purchasing:suggest'));
        $this->assertNotNull($event);
        $this->assertSame('0 6 * * 1', $event->expression);
        $this->assertSame('Australia/Brisbane', $event->timezone);
    }

    public function test_the_suggestion_run_creates_this_weeks_budget_from_the_estimate_only_when_missing()
    {
        $this->dispatchedLastSaturday();

        $this->artisan('purchasing:suggest')->expectsOutputToContain('set to $12.95')->assertSuccessful();

        $budget = PurchasingBudget::sole();
        $this->assertSame('2026-11-16', $budget->week_start->toDateString());
        $this->assertSame('12.95', $budget->budget);
        $this->assertSame('auto-estimate – check against Xero', $budget->notes);

        // Edited by hand, it's kept: neither the command nor the button replaces it.
        $budget->update(['budget' => 800, 'notes' => 'checked']);
        $this->artisan('purchasing:suggest')->assertSuccessful();
        $this->actingAs($this->manager)->post(route('purchase-orders.suggest'))->assertSessionHasNoErrors();
        $this->assertSame('800.00', PurchasingBudget::sole()->budget);

        // Next week has no row until the run that week: approving then is over budget.
        Carbon::setTestNow(Carbon::parse('2026-11-23 09:00', 'Australia/Brisbane'));
        $this->assertNull(app(PurchasingBudgetService::class)->summary()['budget']);
        $this->expectException(OverBudget::class);
        app(PurchaseOrderService::class)->approve($this->draft(1), $this->manager);
    }

    public function test_backfill_sets_payment_due_on_open_pos_from_expected_or_order_date_plus_terms()
    {
        $service = app(PurchaseOrderService::class);
        $make = function (PurchaseOrderStatus $status, ?string $expected, bool $paid = false) use ($service) {
            $po = $service->approve($this->draft(1, '12', $expected), $this->manager, true, 'test');
            $po->forceFill(['status' => $status, 'payment_due_date' => null, 'paid_at' => $paid ? now() : null])->save();

            return $po;
        };
        $sent = $make(PurchaseOrderStatus::Sent, '2026-11-20');
        $partial = $make(PurchaseOrderStatus::PartiallyReceived, null); // order date 16 Nov
        $received = $make(PurchaseOrderStatus::Received, '2026-11-18');
        $paid = $make(PurchaseOrderStatus::Paid, '2026-11-18', true);
        $cancelled = $make(PurchaseOrderStatus::Cancelled, '2026-11-18');
        $prepaid = $make(PurchaseOrderStatus::Sent, '2026-11-18', true);

        $this->artisan('purchasing:backfill-payment-due', ['--dry-run' => true])->expectsOutputToContain('3 PO(s) would be updated')->assertSuccessful();
        $this->assertNull($sent->fresh()->payment_due_date);

        $this->artisan('purchasing:backfill-payment-due')->expectsOutputToContain('3 PO(s) updated')->assertSuccessful();
        $this->assertSame('2026-12-20', $sent->fresh()->payment_due_date->toDateString()); // + 30 days
        $this->assertSame('2026-12-16', $partial->fresh()->payment_due_date->toDateString());
        $this->assertSame('2026-12-18', $received->fresh()->payment_due_date->toDateString());
        foreach ([$paid, $cancelled, $prepaid] as $po) {
            $this->assertNull($po->fresh()->payment_due_date);
        }
        $this->artisan('purchasing:backfill-payment-due')->expectsOutputToContain('0 PO(s) updated')->assertSuccessful();
    }

    // --- Approval and the weekly budget ------------------------------------------------------------------------

    public function test_approval_sets_the_payment_due_date_and_sending_needs_approval()
    {
        $this->budget(10000);
        $service = app(PurchaseOrderService::class);
        $withDate = $this->draft(1, '12', '2026-11-20');

        $this->expectExceptionObject(new InvalidMovement("Approve {$withDate->number} before sending it."));
        try {
            $service->markSent($withDate, $this->manager);
        } finally {
            $approved = $service->approve($withDate, $this->manager);
            $this->assertSame('2026-12-20', $approved->payment_due_date->toDateString()); // delivery + 30 days
            $this->assertSame($this->manager->id, $approved->approved_by, 'the raiser may approve');
            $this->assertNull($approved->override_reason);
            $this->assertSame(PurchaseOrderStatus::Sent, $service->markSent($approved, $this->manager)->status);

            // No expected date: approved today + 5 days lead + 30 days terms.
            $this->assertSame('2026-12-21', $service->approve($this->draft(1), $this->manager)->payment_due_date->toDateString());
        }
    }

    public function test_committed_counts_pos_approved_this_brisbane_week()
    {
        $this->budget(1000);
        $service = app(PurchaseOrderService::class);
        $service->approve($this->draft(50), $this->manager); // $600
        $lastWeek = $service->approve($this->draft(10), $this->manager);
        $lastWeek->forceFill(['approved_at' => Carbon::parse('2026-11-15 23:30', 'Australia/Brisbane')->utc()])->save(); // Sunday night, Brisbane
        $cancelled = $service->approve($this->draft(5), $this->manager);
        $service->cancel($cancelled, $this->manager, 'test');

        $summary = app(PurchasingBudgetService::class)->summary();

        $this->assertSame(['week_start' => '2026-11-16', 'week_end' => '2026-11-22', 'budget' => 1000.0, 'committed' => 600.0, 'remaining' => 400.0], $summary);
    }

    public function test_over_budget_needs_an_override_and_a_reason_which_is_kept()
    {
        $this->budget(1000);
        app(PurchaseOrderService::class)->approve($this->draft(75), $this->manager); // $900 committed
        $po = $this->draft(20); // $240 > $100 remaining

        $this->actingAs($this->manager)->get(route('purchase-orders.show', $po))->assertInertia(fn (AssertableInertia $p) => $p
            ->where('budget.over', true)->where('budget.remaining', 100)->where('budget.po_total', 240)->where('can.approve', true));

        $this->actingAs($this->manager)->post(route('purchase-orders.approve', $po))->assertSessionHasErrors('budget');
        $this->assertSame(PurchaseOrderStatus::Draft, $po->fresh()->status);
        $this->actingAs($this->manager)->post(route('purchase-orders.approve', $po), ['override' => true, 'override_reason' => ''])->assertSessionHasErrors('override_reason');

        $this->actingAs($this->manager)->post(route('purchase-orders.approve', $po), ['override' => true, 'override_reason' => 'Christmas stock-up'])->assertSessionHasNoErrors();
        $po->refresh();
        $this->assertSame(PurchaseOrderStatus::Approved, $po->status);
        $this->assertSame('Christmas stock-up', $po->override_reason);
        $this->assertSame(-140.0, app(PurchasingBudgetService::class)->summary()['remaining']);
    }

    public function test_no_budget_set_counts_as_over_budget()
    {
        $this->expectException(OverBudget::class);
        app(PurchaseOrderService::class)->approve($this->draft(1), $this->manager);
    }

    public function test_mark_paid_on_received_po_makes_it_paid()
    {
        $po = app(PurchaseOrderService::class)->approve($this->draft(1), $this->manager, true, 'test');
        $po->forceFill(['status' => PurchaseOrderStatus::Received])->save();

        $this->actingAs($this->manager)->post(route('purchase-orders.paid', $po))->assertSessionHasNoErrors();

        $po->refresh();
        $this->assertSame(PurchaseOrderStatus::Paid, $po->status);
        $this->assertSame($this->manager->id, $po->paid_by);
        $this->assertNotNull($po->paid_at);
    }

    public function test_mark_paid_is_allowed_from_approved_to_closed_but_not_before_approval_or_when_cancelled()
    {
        $service = app(PurchaseOrderService::class);
        foreach ([PurchaseOrderStatus::Approved, PurchaseOrderStatus::Sent, PurchaseOrderStatus::PartiallyReceived, PurchaseOrderStatus::Closed] as $status) {
            $po = $service->approve($this->draft(1), $this->manager, true, 'test');
            $po->forceFill(['status' => $status])->save();
            $this->actingAs($this->manager)->post(route('purchase-orders.paid', $po))->assertSessionHasNoErrors();
            $po->refresh();
            $this->assertNotNull($po->paid_at, $status->value);
            $this->assertSame($status, $po->status, "{$status->value}: paid early keeps its status, so it can still be sent and received");
        }

        foreach ([PurchaseOrderStatus::Suggested, PurchaseOrderStatus::Draft, PurchaseOrderStatus::Cancelled] as $status) {
            $po = $this->draft(1);
            $po->forceFill(['status' => $status])->save();
            $this->actingAs($this->manager)->post(route('purchase-orders.paid', $po))->assertSessionHasErrors('po');
            $this->assertNull($po->fresh()->paid_at, $status->value);
        }
    }

    public function test_a_prepaid_po_becomes_paid_when_fully_received()
    {
        $service = app(PurchaseOrderService::class);
        $po = $service->markSent($service->approve($this->draft(2), $this->manager, true, 'test'), $this->manager);
        $service->markPaid($po, $this->manager);
        $line = $po->lines()->first();

        $receive = fn (int $qty) => $this->actingAs($this->manager)->post(route('purchase-orders.receipts.store', $po), [
            'client_token' => (string) Str::uuid(),
            'lines' => [['purchase_order_line_id' => $line->id, 'accepted' => ['each' => $qty], 'location_id' => $this->bin()->id]],
        ])->assertSessionHasNoErrors();

        $receive(6);
        $this->assertSame(PurchaseOrderStatus::PartiallyReceived, $po->fresh()->status);
        $receive(6);
        $this->assertSame(PurchaseOrderStatus::Paid, $po->fresh()->status);
    }

    // --- Budgets page --------------------------------------------------------------------------------------------

    public function test_a_weeks_budget_is_estimated_from_last_weeks_dispatches_and_can_be_set()
    {
        $this->dispatchedLastSaturday();

        $this->assertSame(['amount' => 12.95, 'sales' => 17.5, 'from' => '2026-11-09', 'to' => '2026-11-15'], app(PurchasingBudgetService::class)->estimate('2026-11-16'));

        $this->actingAs($this->manager)->get(route('purchasing-budgets.index'))->assertInertia(fn (AssertableInertia $p) => $p
            ->component('purchasing/Budgets')->has('weeks', 13)
            ->where('weeks.4.is_current', true)->where('weeks.4.budget', null)->where('weeks.4.estimate.amount', 12.95));

        $this->actingAs($this->manager)->post(route('purchasing-budgets.store'), ['week_start' => '2026-11-17', 'budget' => 500])->assertSessionHasErrors('week_start');
        $this->actingAs($this->manager)->post(route('purchasing-budgets.store'), ['week_start' => '2026-11-16', 'budget' => 500, 'notes' => 'first week'])->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post(route('purchasing-budgets.store'), ['week_start' => '2026-11-16', 'budget' => 650])->assertSessionHasNoErrors();
        $this->assertSame('650.00', PurchasingBudget::sole()->budget);
        $this->actingAs($this->userWithRole(Permissions::ROLE_READ_ONLY))->post(route('purchasing-budgets.store'), ['week_start' => '2026-11-23', 'budget' => 1])->assertForbidden();
    }

    // --- Cash out and slow movers --------------------------------------------------------------------------------

    public function test_cash_out_shows_payments_by_due_week_and_supplier_against_indicative_inflow()
    {
        $this->history($this->milk, 2); // 2 a day × 7 × $3 = $42 a week
        $service = app(PurchaseOrderService::class);
        $this->dairy->update(['payment_terms_days' => 7]);
        $service->approve($this->draft(20, '12', '2026-11-20', tax: 10), $this->manager, true, 'test'); // $240 + $24 GST due 27 Nov
        $service->approve($this->draft(5, '12', '2026-11-20'), $this->manager, true, 'test'); // $60 GST-free, due 27 Nov
        $old = $service->approve($this->draft(1), $this->manager, true, 'test');
        $old->forceFill(['status' => PurchaseOrderStatus::Closed, 'payment_due_date' => '2026-11-10'])->save(); // short-shipped, overdue, unpaid
        $paid = $service->approve($this->draft(5, '12', '2026-11-20'), $this->manager, true, 'test');
        $paid->forceFill(['status' => PurchaseOrderStatus::Paid, 'paid_at' => now()])->save();
        $prepaid = $service->markSent($service->approve($this->draft(5, '12', '2026-11-20'), $this->manager, true, 'test'), $this->manager);
        $service->markPaid($prepaid, $this->manager); // paid before delivery: not owed any more

        $r = app(CashOutReport::class)->build();

        $this->assertCount(13, $r['weeks']);
        // Milk isn't GST-free here: inflow $42 ex, $46.20 inc.
        $this->assertSame(['start' => '2026-11-16', 'end' => '2026-11-22', 'payments' => 0.0, 'payments_ex' => 0.0,
            'inflow' => 46.2, 'inflow_ex' => 42.0, 'net' => 46.2, 'net_ex' => 42.0], $r['weeks'][0]);
        $this->assertSame(['start' => '2026-11-23', 'end' => '2026-11-29', 'payments' => 324.0, 'payments_ex' => 300.0,
            'inflow' => 46.2, 'inflow_ex' => 42.0, 'net' => -277.8, 'net_ex' => -258.0], $r['weeks'][1]);
        $this->assertSame('Dairy Co', $r['suppliers'][0]['name']);
        $this->assertSame(324.0, $r['suppliers'][0]['amounts'][1]);
        $this->assertSame(324.0, $r['suppliers'][0]['total']);
        $this->assertSame(300.0, $r['suppliers'][0]['total_ex']);
        $this->assertSame(['count' => 1, 'total' => 12.0, 'total_ex' => 12.0], $r['overdue']);

        $this->actingAs($this->manager)->get(route('purchasing.cash-out'))->assertInertia(fn (AssertableInertia $p) => $p->component('purchasing/CashOut')->has('report.weeks', 13));
    }

    public function test_slow_movers_lists_stock_selling_under_a_tenth_a_day_by_value()
    {
        $this->history($this->milk, 2);
        $this->stockIn($this->milk, 10);
        $slow = Product::factory()->create(['sku' => 'SLOW']);
        $slow->forceFill(['avg_cost' => 4])->save();
        $this->stockIn($slow, 50);
        $dead = Product::factory()->create(['sku' => 'DEAD']);
        $dead->forceFill(['avg_cost' => 10])->save();
        $this->stockIn($dead, 30);
        $this->history($slow, 1, 3); // a few sales a while ago: well under 0.1 a day

        $r = app(SlowMoversReport::class)->build();

        $this->assertSame(['DEAD', 'SLOW'], array_column($r['rows'], 'sku'));
        $this->assertSame(300.0, $r['rows'][0]['value']);
        $this->assertNull($r['rows'][0]['days_cover']);
        $this->assertSame(200.0, $r['rows'][1]['value']);
        $this->assertGreaterThan(0, $r['rows'][1]['days_cover']);
        $this->assertSame(500.0, $r['total_value']);

        $this->actingAs($this->manager)->get(route('purchasing.slow-movers'))->assertInertia(fn (AssertableInertia $p) => $p->component('purchasing/SlowMovers')->has('report.rows', 2));
    }

    public function test_po_lines_show_sell_price_and_gm()
    {
        $po = $this->draft(1, '12');
        app(PurchaseOrderService::class)->saveDraft($po, [
            'supplier_id' => $this->dairy->id, 'site_id' => $this->site()->id, 'order_date' => '2026-11-16',
            'lines' => [
                ['product_id' => $this->milk->id, 'product_unit_id' => $this->milk->units()->where('base_qty', 6)->value('id'), 'qty_ordered' => 1, 'unit_cost' => '12'],
                ['product_id' => $this->milk->id, 'product_unit_id' => $this->milk->units()->where('base_qty', 6)->value('id'), 'qty_ordered' => 1, 'unit_cost' => '15'],
            ],
        ], $this->manager);

        $this->actingAs($this->manager)->get(route('purchase-orders.show', $po))->assertInertia(fn (AssertableInertia $p) => $p
            ->where('gmThreshold', 26)
            ->where('po.lines.0.sell_price', 18) // $3 each × 6
            ->where('po.lines.0.gm_percent', 33.33)
            ->where('po.lines.1.gm_percent', 16.67)
            ->where('po.below_minimum', true));
    }
}
