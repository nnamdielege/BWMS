<?php

namespace Tests\Feature\Orders;

use App\Authorization\Permissions;
use App\Enums\MovementType;
use App\Enums\OrderChannel;
use App\Enums\PurchaseOrderStatus;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\DeliveryArea;
use App\Models\DeliveryRun;
use App\Models\Location;
use App\Models\Order;
use App\Models\PickList;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Reservation;
use App\Models\Setting;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Delivery\DeliveryScheduler;
use App\Services\Orders\ConsolidationService;
use App\Services\Orders\OrderService;
use App\Services\Orders\PickListService;
use App\Services\Orders\PickPackService;
use App\Services\Stock\MovementDraft;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\Support\OrderFixtures;
use Tests\Support\StockFixtures;
use Tests\TestCase;

class ConsolidationTest extends TestCase
{
    use OrderFixtures, RefreshDatabase, StockFixtures;

    private const FRIDAY = '2026-11-13';

    private const MONDAY = '2026-11-16';

    private const TUESDAY = '2026-11-17';

    private const NORTH = ['street' => '1 Test St', 'suburb' => 'Northville', 'postcode' => '4500'];

    private User $manager;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-09 09:00', 'Australia/Brisbane')); // Monday
        $this->seedStockReferenceData();
        $this->manager = $this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER);
        $this->product = Product::factory()->create(['sku' => 'MILK-1']);
        $this->stockIn($this->product, 100);
        DeliveryArea::create(['code' => 'N', 'name' => 'North of Brisbane', 'weekdays' => [1], 'is_active' => true, 'sort_order' => 1])->places()->create(['postcode' => '4500', 'suburb' => '']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function order(string $ref = 'o-1', string $date = self::MONDAY, int $version = 1, int $qty = 2): Order
    {
        return $this->channelOrder(OrderChannel::Ordermentum, $ref, $version, [[$this->product, $qty]], delivery: self::NORTH, date: $date);
    }

    private function move(array $orders, string $date = self::FRIDAY)
    {
        return $this->actingAs($this->manager)->post(route('orders.consolidate.move'), ['date' => $date, 'order_ids' => array_map(fn (Order $o) => $o->id, $orders)]);
    }

    private function undo(Order $order)
    {
        return $this->actingAs($this->manager)->post(route('orders.consolidate.undo', $order));
    }

    private function poExpected(string $date, int $qty, ?Location $site = null, PurchaseOrderStatus $status = PurchaseOrderStatus::Sent): PurchaseOrder
    {
        $po = new PurchaseOrder(['supplier_id' => Supplier::factory()->create()->id, 'site_id' => ($site ?? $this->site())->id, 'order_date' => '2026-11-09', 'expected_date' => $date]);
        $po->forceFill(['number' => 'PO-'.random_int(1000, 9999), 'status' => $status])->save();
        $po->lines()->create(['product_id' => $this->product->id, 'product_unit_id' => $this->product->units()->value('id'), 'qty_ordered' => $qty, 'base_qty_per_unit' => 1, 'qty_ordered_base' => $qty, 'unit_cost' => 1, 'line_subtotal' => $qty]);

        return $po;
    }

    public function test_moving_changes_the_date_and_run_and_keeps_the_area_and_the_original()
    {
        $order = $this->order();
        $mondayRun = $order->deliveryRun;

        $this->move([$order])->assertSessionHas('success', 'Moved 1 order(s).')->assertSessionMissing('error');

        $order->refresh();
        $this->assertSame(self::FRIDAY, $order->delivery_date->toDateString());
        $this->assertSame(self::FRIDAY, $order->deliveryRun->run_date->toDateString());
        $this->assertSame($mondayRun->delivery_area_id, $order->deliveryRun->delivery_area_id);
        $this->assertSame('manager', $order->delivery_date_origin);
        $this->assertSame('consolidated', $order->delivery_date_reason);
        $this->assertSame(self::MONDAY, $order->original_delivery_date->toDateString());
    }

    public function test_a_consolidated_order_is_not_flagged_as_a_non_delivery_day()
    {
        $order = $this->order(); // Friday isn't this area's day

        $this->move([$order]);

        $order->refresh();
        $this->assertFalse($order->needs_review);
        $this->assertNull($order->review_notes);
    }

    public function test_it_stays_unflagged_when_a_customer_change_reassigns_it()
    {
        $order = $this->order();
        $this->move([$order]);

        app(DeliveryScheduler::class)->assign($order->fresh());

        $this->assertFalse($order->fresh()->needs_review);
        $this->assertSame(self::FRIDAY, $order->fresh()->delivery_date->toDateString());
        $this->assertSame('consolidated', $order->fresh()->delivery_date_reason);
    }

    public function test_undo_puts_it_back_on_its_original_date_and_run_with_its_origin()
    {
        $order = $this->order();
        $mondayRun = $order->delivery_run_id;
        $this->move([$order]);

        $this->undo($order)->assertSessionHas('success');

        $order->refresh();
        $this->assertSame(self::MONDAY, $order->delivery_date->toDateString());
        $this->assertSame($mondayRun, $order->delivery_run_id);
        $this->assertSame('source', $order->delivery_date_origin);
        $this->assertNull($order->delivery_date_reason);
        $this->assertNull($order->original_delivery_date);
    }

    public function test_undo_brings_back_a_flag_the_original_date_deserved()
    {
        $order = $this->order(date: '2026-11-18'); // Wednesday: flagged from the start
        $this->assertTrue($order->needs_review);
        $this->move([$order]);
        $this->assertFalse($order->fresh()->needs_review);

        $this->undo($order);

        $this->assertArrayHasKey('not_delivery_day', $order->fresh()->review_notes);
    }

    public function test_undo_is_blocked_on_a_pick_list_and_after_the_original_run_is_dispatched()
    {
        $order = $this->order();
        $this->move([$order]);
        $list = app(PickPackService::class)->startNew($order->fresh()->deliveryRun, $this->manager);

        $this->undo($order)->assertSessionHasErrors('consolidate');
        $this->assertSame(self::FRIDAY, $order->fresh()->delivery_date->toDateString());

        app(PickListService::class)->cancel($list);
        DeliveryRun::whereDate('run_date', self::MONDAY)->update(['status' => DeliveryRun::STATUS_DISPATCHED]);
        $this->undo($order)->assertSessionHasErrors('consolidate');
    }

    public function test_a_second_move_keeps_the_first_original_date()
    {
        $order = $this->order();
        $this->move([$order]);

        $this->move([$order], '2026-11-12');

        $order->refresh();
        $this->assertSame('2026-11-12', $order->delivery_date->toDateString());
        $this->assertSame(self::MONDAY, $order->original_delivery_date->toDateString());
    }

    public function test_the_source_re_sending_the_same_date_leaves_the_move()
    {
        $order = $this->order();
        $this->move([$order]);

        $order = $this->order(version: 2);

        $this->assertSame(self::FRIDAY, $order->delivery_date->toDateString());
        $this->assertSame('consolidated', $order->delivery_date_reason);
        $this->assertFalse($order->needs_review);
    }

    public function test_a_different_source_date_flags_all_three_and_moves_nothing()
    {
        $order = $this->order();
        $this->move([$order]);
        $run = $order->fresh()->delivery_run_id;

        $order = $this->order(date: self::TUESDAY, version: 2);

        $this->assertSame(self::FRIDAY, $order->delivery_date->toDateString());
        $this->assertSame($run, $order->delivery_run_id);
        $this->assertTrue($order->needs_review);
        foreach ([self::MONDAY, self::FRIDAY, self::TUESDAY] as $date) {
            $this->assertStringContainsString($date, $order->review_notes['date_changed']);
        }
    }

    public function test_a_different_date_flags_all_three_on_a_pick_list_too()
    {
        $order = $this->order();
        $this->move([$order]);
        app(PickPackService::class)->startNew($order->fresh()->deliveryRun, $this->manager);

        $order = $this->order(date: self::TUESDAY, version: 2);

        $this->assertStringContainsString('consolidated to '.self::FRIDAY, $order->review_notes['date_changed']);
    }

    public function test_the_source_coming_round_to_either_date_clears_the_flag()
    {
        $order = $this->order();
        $this->move([$order]);
        $this->order(date: self::TUESDAY, version: 2);

        $order = $this->order(date: self::FRIDAY, version: 3);
        $this->assertFalse($order->needs_review);

        $this->order(date: self::TUESDAY, version: 4);
        $this->assertFalse($this->order(date: self::MONDAY, version: 5)->needs_review);
    }

    public function test_undo_after_the_source_moved_on_goes_back_and_keeps_it_flagged()
    {
        $order = $this->order();
        $this->move([$order]);
        $this->order(date: self::TUESDAY, version: 2);

        $this->undo($order);

        $order->refresh();
        $this->assertSame(self::MONDAY, $order->delivery_date->toDateString());
        $this->assertStringContainsString(self::TUESDAY, $order->review_notes['date_changed']);
    }

    public function test_orders_on_a_pick_list_are_blocked_and_the_rest_still_move()
    {
        $picking = $this->order('o-1');
        app(PickPackService::class)->startNew($picking->deliveryRun, $this->manager);
        $free = $this->order('o-2');

        $this->move([$picking, $free])->assertSessionHas('success', 'Moved 1 order(s).')->assertSessionHas('error', fn ($m) => str_contains($m, 'already on a pick list'));

        $this->assertSame(self::MONDAY, $picking->fresh()->delivery_date->toDateString());
        $this->assertSame(self::FRIDAY, $free->fresh()->delivery_date->toDateString());
    }

    public function test_a_cancelled_pick_list_frees_the_order()
    {
        $order = $this->order();
        app(PickListService::class)->cancel(app(PickPackService::class)->startNew($order->deliveryRun, $this->manager));

        $this->move([$order])->assertSessionHas('success', 'Moved 1 order(s).');
    }

    public function test_pick_lists_still_never_mix_days_after_a_move()
    {
        $moved = $this->order('o-1');
        $stays = $this->order('o-2');
        $this->move([$moved]);

        $list = app(PickPackService::class)->startNew($moved->fresh()->deliveryRun, $this->manager);

        $this->assertSame([$moved->id], $list->lines()->with('orderLine')->get()->pluck('orderLine.order_id')->unique()->values()->all());
        $this->assertSame('open', $stays->fresh()->status->value);
    }

    public function test_only_earlier_days_and_open_runs_are_allowed()
    {
        $order = $this->order();
        $this->move([$order], self::TUESDAY)->assertSessionHas('error', fn ($m) => str_contains($m, 'earlier day'));
        $this->move([$order], '2026-11-06')->assertSessionHas('error', fn ($m) => str_contains($m, 'passed'));

        $run = app(DeliveryScheduler::class)->run($this->site()->id, DeliveryArea::first(), CarbonImmutable::parse(self::FRIDAY));
        $run->forceFill(['status' => DeliveryRun::STATUS_DISPATCHED])->save();
        $this->move([$order])->assertSessionHas('error', fn ($m) => str_contains($m, 'been dispatched'));

        $this->assertSame(self::MONDAY, $order->fresh()->delivery_date->toDateString());
    }

    public function test_moving_changes_no_stock()
    {
        $order = $this->order();
        $before = [StockMovement::count(), StockBalance::sum('on_hand'), Reservation::sum('qty_base')];

        $this->move([$order]);
        $this->undo($order);

        $this->assertSame($before, [StockMovement::count(), StockBalance::sum('on_hand'), Reservation::sum('qty_base')]);
    }

    public function test_the_warning_names_the_po_when_the_stock_only_arrives_after_the_new_day()
    {
        $this->product->update(['sku' => 'OAT-1']);
        $order = $this->order(qty: 80);
        $this->stockInvisible(90);
        $po = $this->poExpected(self::MONDAY, 200);

        $this->move([$order])->assertSessionHas('error', "Stock for OAT-1 arrives Mon via {$po->number}; not available for Fri.");

        $this->assertSame(["Stock for OAT-1 arrives Mon via {$po->number}; not available for Fri"], AuditLog::where('event', 'order_consolidated')->sole()->meta['warnings']);
        $this->assertSame(self::FRIDAY, $order->fresh()->delivery_date->toDateString(), 'A warning, not a block');
    }

    public function test_no_warning_with_enough_stock_or_a_po_outside_the_window()
    {
        $order = $this->order(qty: 80);
        $service = app(ConsolidationService::class);
        $this->poExpected(self::MONDAY, 200);
        $this->assertSame([], $service->stockWarnings($order->fresh(['lines.product', 'site']), self::FRIDAY), 'Enough on hand');

        $this->stockInvisible(90);
        $this->assertNotSame([], $service->stockWarnings($order->fresh(['lines.product', 'site']), self::FRIDAY));

        PurchaseOrder::query()->update(['expected_date' => '2026-11-12']);
        $this->assertSame([], $service->stockWarnings($order->fresh(['lines.product', 'site']), self::FRIDAY), 'Arrives before the new day');

        PurchaseOrder::query()->update(['expected_date' => '2026-11-20']);
        $this->assertSame([], $service->stockWarnings($order->fresh(['lines.product', 'site']), self::FRIDAY), 'Arrives after the original date');

        PurchaseOrder::query()->update(['expected_date' => self::MONDAY, 'status' => PurchaseOrderStatus::Draft->value]);
        $this->assertSame([], $service->stockWarnings($order->fresh(['lines.product', 'site']), self::FRIDAY), 'Not on order yet');
    }

    public function test_a_later_order_doesnt_count_against_the_day()
    {
        $this->stockInvisible(90); // 10 left
        $later = $this->order('o-later', '2026-11-20', qty: 8);
        $order = $this->order('o-1', qty: 5);
        $this->poExpected(self::MONDAY, 200);

        $warnings = app(ConsolidationService::class)->stockWarnings($order->fresh(['lines.product', 'site']), self::FRIDAY);

        $this->assertSame([], $warnings, 'The later order waits its turn');
        $this->assertSame('open', $later->fresh()->status->value);
    }

    public function test_each_move_and_undo_is_audited()
    {
        $order = $this->order();
        $this->move([$order]);
        $this->undo($order);

        $moved = AuditLog::where('event', 'order_consolidated')->sole();
        $this->assertSame((int) $moved->auditable_id, $order->id);
        $this->assertSame($this->manager->id, $moved->user_id);
        $this->assertSame([['delivery_date' => self::MONDAY], ['delivery_date' => self::FRIDAY]], [$moved->old_values, $moved->new_values]);
        $this->assertSame(['consolidated', self::MONDAY], [$moved->meta['reason'], $moved->meta['original_date']]);

        $undone = AuditLog::where('event', 'order_consolidation_undone')->sole();
        $this->assertSame([['delivery_date' => self::FRIDAY], ['delivery_date' => self::MONDAY]], [$undone->old_values, $undone->new_values]);
    }

    public function test_the_screen_shows_the_day_and_the_candidates_by_size_value_and_pick_list()
    {
        $customer = Customer::factory()->create();
        $priced = app(OrderService::class)->createManual($this->manager, [
            'customer_id' => $customer->id, 'site_id' => $this->site()->id, 'short_ship_policy' => 'backorder', 'requested_delivery_date' => self::FRIDAY,
            'lines' => [['product_id' => $this->product->id, 'product_unit_id' => $this->product->units()->value('id'), 'qty' => 3, 'unit_price' => '10.50']],
        ]);
        $customer->update(['delivery_postcode' => '4500']);
        app(DeliveryScheduler::class)->assign($priced->fresh());
        $candidate = $this->order('o-1');
        $picking = $this->order('o-2', self::TUESDAY);
        $list = app(PickPackService::class)->startNew($picking->deliveryRun, $this->manager);
        $this->order('o-far', '2026-11-27');

        $this->actingAs($this->manager)->get(route('orders.consolidate.index', ['date' => self::FRIDAY]))->assertInertia(fn (AssertableInertia $p) => $p
            ->component('orders/Consolidate')
            ->where('target', self::FRIDAY)->where('days', 7)
            ->where('runs.0.orders', 1)->where('runs.0.packs', 3)->where('runs.0.value', '31.50')
            ->where('candidates', fn ($rows) => collect($rows)->pluck('id')->all() === [$candidate->id, $picking->id])
            ->where('candidates.0.packs', 2)->where('candidates.0.value', null)->where('candidates.0.pick_list', null)->where('candidates.0.channel', 'ordermentum')
            ->where('candidates.1.pick_list', $list->number));
    }

    public function test_look_ahead_widens_the_window()
    {
        $far = $this->order('o-far', '2026-11-27');

        $this->actingAs($this->manager)->get(route('orders.consolidate.index', ['date' => self::FRIDAY, 'days' => 14]))
            ->assertInertia(fn (AssertableInertia $p) => $p->where('candidates.0.id', $far->id));
    }

    public function test_the_screen_is_for_managers_and_admins()
    {
        foreach ([Permissions::ROLE_WAREHOUSE_STAFF, Permissions::ROLE_PURCHASING, Permissions::ROLE_READ_ONLY] as $role) {
            $this->actingAs($this->userWithRole($role))->get(route('orders.consolidate.index'))->assertForbidden();
        }
        $this->actingAs($this->userWithRole(Permissions::ROLE_WAREHOUSE_STAFF))->post(route('orders.consolidate.move'), ['date' => self::FRIDAY, 'order_ids' => [1]])->assertForbidden();
    }

    public function test_the_badge_shows_on_the_order_the_queue_and_the_manifest()
    {
        $order = $this->order();
        $this->move([$order]);
        $run = $order->fresh()->deliveryRun;

        $this->actingAs($this->manager)->get(route('orders.show', $order))->assertInertia(fn (AssertableInertia $p) => $p->where('order.moved_from', self::MONDAY));
        $this->actingAs($this->manager)->get(route('pick.manifest', $run))->assertInertia(fn (AssertableInertia $p) => $p->where('orders.0.moved_from', self::MONDAY));
        $this->actingAs($this->manager)->get(route('pick.queue'))->assertInertia(fn (AssertableInertia $p) => $p
            ->where('runs', fn ($runs) => collect($runs)->firstWhere('id', $run->id)['orders'][0]['moved_from'] === self::MONDAY));
    }

    public function test_trips_group_the_manifest_without_changing_runs()
    {
        DeliveryArea::create(['code' => 'S', 'name' => 'South', 'weekdays' => [5], 'is_active' => true, 'sort_order' => 2])->places()->create(['postcode' => '4600', 'suburb' => '']);
        DeliveryArea::create(['code' => 'E', 'name' => 'East', 'weekdays' => [5], 'is_active' => true, 'sort_order' => 3])->places()->create(['postcode' => '4700', 'suburb' => '']);
        $make = fn ($ref, $postcode) => $this->channelOrder(OrderChannel::Portal, $ref, 1, [[$this->product, 1]], delivery: ['postcode' => $postcode], date: self::FRIDAY);
        $north = $make('a', '4500');
        $south = $make('b', '4600');
        $east = $make('c', '4700');
        $runs = DeliveryRun::count();

        $this->actingAs($this->manager)->put(route('orders.consolidate.trips'), ['labels' => [$north->delivery_run_id => 'Van 1', $south->delivery_run_id => 'Van 1', $east->delivery_run_id => null]])->assertSessionHasNoErrors();

        $this->actingAs($this->manager)->get(route('pick.trips', self::FRIDAY))->assertInertia(fn (AssertableInertia $p) => $p
            ->component('warehouse/TripManifest')
            ->where('trips', fn ($trips) => collect($trips)->map(fn ($t) => [$t['label'], collect($t['runs'])->pluck('area')->all(), $t['orders']])->all() === [
                ['Van 1', ['North of Brisbane', 'South'], 2],
                ['East', ['East'], 1],
            ]));
        $this->assertSame($runs, DeliveryRun::count());
        $this->assertNotSame($north->delivery_run_id, $south->delivery_run_id);
        $this->assertSame(0, PickList::count());
    }

    public function test_the_customer_list_is_off_by_default_and_sends_nothing()
    {
        $customer = Customer::factory()->create(['name' => 'Cafe', 'phone' => '0400 000 000', 'email' => 'cafe@example.com']);
        $order = $this->order();
        $order->forceFill(['customer_id' => $customer->id])->save();
        $this->move([$order]);
        $page = fn () => $this->actingAs($this->manager)->get(route('orders.consolidate.index', ['date' => self::FRIDAY]));

        $page()->assertInertia(fn (AssertableInertia $p) => $p->where('noticeEnabled', false)->where('notice', []));

        $this->actingAs($this->manager)->put(route('orders.consolidate.notice'), ['enabled' => true]);

        $this->assertSame('1', Setting::get(ConsolidationService::NOTICE_SETTING));
        $page()->assertInertia(fn (AssertableInertia $p) => $p->where('noticeEnabled', true)
            ->where('notice.0.customer', 'Cafe')->where('notice.0.phone', '0400 000 000')->where('notice.0.email', 'cafe@example.com')->where('notice.0.moved_from', self::MONDAY));
    }

    public function test_the_backfill_leaves_a_consolidated_order_alone()
    {
        $order = $this->channelOrder(OrderChannel::Portal, 'p-1', 1, [[$this->product, 1]], delivery: self::NORTH, date: self::MONDAY);
        $this->move([$order]);

        $this->artisan('orders:backfill-delivery-dates', ['--yes' => true])->assertSuccessful();

        $this->assertSame(self::FRIDAY, $order->fresh()->delivery_date->toDateString());
        $this->assertSame('consolidated', $order->fresh()->delivery_date_reason);
    }

    public function test_a_managers_later_date_change_ends_the_consolidation()
    {
        $order = $this->order();
        $this->move([$order]);

        $this->actingAs($this->manager)->put(route('orders.delivery-date', $order), ['delivery_date' => '2026-11-12'])->assertSessionHas('success');

        $order->refresh();
        $this->assertNull($order->delivery_date_reason);
        $this->assertNull($order->original_delivery_date);
    }

    /** Takes stock out of what's sellable, so an order has to wait for a PO. */
    private function stockInvisible(int $qty): void
    {
        $quarantine = Location::where('parent_id', $this->site()->id)->where('is_sellable', false)->first()
            ?? Location::create(['type' => 'bin', 'parent_id' => $this->site()->id, 'code' => 'Q-1', 'name' => 'Q', 'is_sellable' => false]);
        $this->ledger()->post([
            new MovementDraft(product: $this->product, location: $this->bin(), qty: -$qty, type: MovementType::TransferOut, idempotencyKey: 'test-out-'.$qty),
            new MovementDraft(product: $this->product, location: $quarantine, qty: $qty, type: MovementType::TransferIn, idempotencyKey: 'test-in-'.$qty),
        ]);
    }
}
