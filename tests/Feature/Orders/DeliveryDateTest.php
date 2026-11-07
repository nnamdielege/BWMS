<?php

namespace Tests\Feature\Orders;

use App\Authorization\Permissions;
use App\Enums\OrderChannel;
use App\Integrations\Ordermentum\OrderIngestor;
use App\Integrations\Ordermentum\OrdermentumOrderMapper;
use App\Integrations\Portal\PortalSignature;
use App\Models\AuditLog;
use App\Models\ChannelListing;
use App\Models\Customer;
use App\Models\DeliveryArea;
use App\Models\Order;
use App\Models\PickList;
use App\Models\Product;
use App\Models\User;
use App\Services\Delivery\DeliveryScheduler;
use App\Services\Orders\OrderService;
use App\Services\Orders\PickListService;
use App\Services\Orders\PickPackService;
use App\Services\Stock\Exceptions\InvalidMovement;
use App\Support\BusinessDate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\OrderFixtures;
use Tests\Support\StockFixtures;
use Tests\TestCase;

class DeliveryDateTest extends TestCase
{
    use OrderFixtures, RefreshDatabase, StockFixtures;

    private const MONDAY_ONLY = ['street' => '1 Test St', 'suburb' => 'Mondayville', 'postcode' => '4500'];

    private const NEXT_DAY = ['street' => '2 Test St', 'suburb' => 'Quickton', 'postcode' => '4600'];

    private User $manager;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-02 09:00', 'Australia/Brisbane')); // Monday
        $this->seedStockReferenceData();
        $this->manager = $this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER);
        $this->product = Product::factory()->create();
        $this->stockIn($this->product, 500);

        DeliveryArea::create(['code' => 'MON', 'name' => 'Monday area', 'weekdays' => [1], 'is_active' => true, 'sort_order' => 1])->places()->create(['postcode' => '4500', 'suburb' => '']);
        DeliveryArea::create(['code' => 'NEXT', 'name' => 'Next-day area', 'weekdays' => [1], 'next_day_eligible' => true, 'is_active' => true, 'sort_order' => 2])->places()->create(['postcode' => '4600', 'suburb' => '']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function order(?string $date, array $delivery = self::MONDAY_ONLY, int $version = 1, OrderChannel $channel = OrderChannel::Ordermentum, string $ref = 'o-1'): Order
    {
        return $this->channelOrder($channel, $ref, $version, [[$this->product, 1]], delivery: $delivery, date: $date);
    }

    private function runDate(Order $order): ?string
    {
        return $order->fresh()->deliveryRun?->run_date->toDateString();
    }

    private function pick(Order $order): PickList
    {
        return app(PickPackService::class)->startNew($order->fresh()->deliveryRun, $this->manager);
    }

    /** @return array<string, array{0: mixed, 1: ?string}> */
    public static function sourceDates(): array
    {
        return [
            'Brisbane midnight, as Ordermentum sends it' => ['2026-03-09T14:00:00.000Z', '2026-03-10'],
            'one second before Brisbane midnight' => ['2026-03-09T13:59:59.000Z', '2026-03-09'],
            'just after UTC midnight' => ['2026-03-09T00:00:01.000Z', '2026-03-09'],
            'last second of the UTC day' => ['2026-03-09T23:59:59.000Z', '2026-03-10'],
            'with an offset' => ['2026-03-09T23:00:00+10:00', '2026-03-09'],
            'a plain date stays that date' => ['2026-03-09', '2026-03-09'],
            'an impossible date' => ['2026-02-31', null],
            'words' => ['next Tuesday', null],
            'a number' => [20260309, null],
            'nothing' => [null, null],
        ];
    }

    #[DataProvider('sourceDates')]
    public function test_a_source_date_is_read_in_brisbane(mixed $value, ?string $expected)
    {
        $this->assertSame($expected, BusinessDate::parse($value));
    }

    public function test_the_ordermentum_date_field_is_configurable_and_the_first_with_a_value_wins()
    {
        $map = config('bwms.ordermentum.order_mapping');
        $mapper = new OrdermentumOrderMapper(['delivery_date' => ['custom.date', 'deliveryDate']] + $map);

        $this->assertSame('2026-03-10', $mapper->deliveryDate(['custom' => ['date' => ''], 'deliveryDate' => '2026-03-09T14:00:00.000Z']));
        $this->assertSame('2026-04-01', $mapper->deliveryDate(['custom' => ['date' => '2026-04-01'], 'deliveryDate' => '2026-03-09T14:00:00.000Z']));
        $this->assertNull($mapper->deliveryDate([]));
    }

    public function test_an_ordermentum_order_lands_on_the_brisbane_date_it_sent()
    {
        config(['services.ordermentum.api_key' => 'k', 'services.ordermentum.base_url' => 'https://api.om.test', 'bwms.ordermentum.site_code' => $this->site()->code]);
        ChannelListing::create(['channel' => 'ordermentum', 'product_id' => $this->product->id, 'product_unit_id' => $this->product->units()->value('id'), 'external_variant_id' => 'var-1']);
        Http::fake(['api.om.test/v1/orders/o-9*' => Http::response([
            'id' => 'o-9', 'status' => 'Placed', 'updatedAt' => '2026-11-02T01:00:00.000Z', 'deliveryDate' => '2026-11-08T14:00:00.000Z', // Monday 9th in Brisbane
            'address' => ['suburb' => 'Mondayville', 'postcode' => '4500'], 'lineItems' => [['id' => 'l1', 'variantId' => 'var-1', 'quantity' => 1]],
        ])]);

        app(OrderIngestor::class)->ingest('o-9');

        $order = Order::sole();
        $this->assertSame('2026-11-09', $order->delivery_date->toDateString());
        $this->assertSame('2026-11-09', $this->runDate($order));
        $this->assertFalse($order->needs_review);
    }

    private function portal(array $body)
    {
        config(['bwms.portal.api_enabled' => true, 'bwms.portal.inbound_keys' => ['k1' => 's1']]);
        ChannelListing::firstOrCreate(['channel' => 'portal', 'external_variant_id' => '501'], ['product_id' => $this->product->id, 'product_unit_id' => $this->product->units()->value('id'), 'external_product_id' => '501']);
        $raw = json_encode($body + ['order_ref' => 'P-1', 'delivery' => self::MONDAY_ONLY, 'lines' => [['line_ref' => 'a', 'portal_product_id' => 501, 'qty' => 1]]]);
        $ts = (string) time();

        return $this->call('POST', '/api/portal/v1/reservations', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => 'k-'.md5($raw),
            'HTTP_X_BWMS_KEY_ID' => 'k1', 'HTTP_X_BWMS_TIMESTAMP' => $ts, 'HTTP_X_BWMS_SIGNATURE' => PortalSignature::sign($ts, $raw, 's1'),
        ], $raw);
    }

    public function test_a_portal_order_uses_the_date_it_sent()
    {
        $this->portal(['requested_delivery_date' => '2026-11-09'])->assertStatus(201);

        $this->assertSame('2026-11-09', Order::sole()->delivery_date->toDateString());
        $this->assertFalse(Order::sole()->needs_review);
    }

    public function test_a_portal_date_that_cant_be_read_falls_back_and_flags_instead_of_refusing_the_order()
    {
        $this->portal(['requested_delivery_date' => 'whenever'])->assertStatus(201);

        $order = Order::sole();
        $this->assertSame('2026-11-09', $order->delivery_date->toDateString(), 'Monday area: next run after the cutoff');
        $this->assertSame(['no_date' => 'No delivery date from Portal'], $order->review_notes);
        $this->assertTrue($order->needs_review);
    }

    public function test_the_manual_form_requires_a_date_and_offers_the_customers_next_delivery_day()
    {
        $customer = Customer::factory()->create(['delivery_postcode' => '4500']);
        $line = ['product_id' => $this->product->id, 'product_unit_id' => $this->product->units()->value('id'), 'qty' => 1];
        $form = ['customer_id' => $customer->id, 'site_id' => $this->site()->id, 'short_ship_policy' => 'backorder', 'lines' => [$line]];

        $this->actingAs($this->manager)->post(route('orders.store'), $form)->assertSessionHasErrors('requested_delivery_date');
        $this->actingAs($this->manager)->getJson(route('orders.next-date', $customer))->assertExactJson(['date' => '2026-11-09']);
        $this->actingAs($this->manager)->getJson(route('orders.next-date', Customer::factory()->create()))->assertExactJson(['date' => null]);

        $this->actingAs($this->manager)->post(route('orders.store'), $form + ['requested_delivery_date' => '2026-11-16'])->assertSessionHasNoErrors();
        $order = Order::sole();
        $this->assertSame('2026-11-16', $order->delivery_date->toDateString());
        $this->assertSame('2026-11-16', $this->runDate($order));
    }

    public function test_no_date_from_the_source_uses_the_area_cutoff_and_flags_it()
    {
        $order = $this->order(null);

        $this->assertSame('2026-11-09', $order->delivery_date->toDateString());
        $this->assertSame('2026-11-09', $this->runDate($order));
        $this->assertSame('fallback', $order->delivery_date_origin);
        $this->assertSame(['no_date' => 'No delivery date from Ordermentum'], $order->review_notes);
    }

    public function test_the_flag_clears_when_the_source_later_sends_a_date()
    {
        $this->order(null);
        $order = $this->order('2026-11-16', version: 2);

        $this->assertNull($order->review_notes);
        $this->assertFalse($order->needs_review);
        $this->assertSame('2026-11-16', $order->delivery_date->toDateString());
    }

    public function test_no_date_and_no_area_stays_unassigned_and_flagged()
    {
        $order = $this->order(null, ['postcode' => '9999']);

        $this->assertNull($order->delivery_date);
        $this->assertNull($order->delivery_run_id);
        $this->assertSame('unassigned', $order->run_assignment);
        $this->assertTrue($order->needs_review);
    }

    public function test_a_source_date_with_no_area_still_sets_the_date()
    {
        $order = $this->order('2026-11-12', ['postcode' => '9999']);

        $this->assertSame('2026-11-12', $order->delivery_date->toDateString());
        $this->assertNull($order->delivery_run_id);
    }

    public function test_a_date_the_area_doesnt_run_is_kept_and_flagged_never_moved()
    {
        $order = $this->order('2026-11-04'); // Wednesday, Monday area

        $this->assertSame('2026-11-04', $order->delivery_date->toDateString());
        $this->assertSame('2026-11-04', $this->runDate($order), 'A run on that date, not the next Monday');
        $this->assertSame(['not_delivery_day' => 'Not a delivery day for Monday area'], $order->review_notes);
    }

    public function test_a_next_day_area_runs_any_business_day_but_not_a_weekend()
    {
        $this->assertFalse($this->order('2026-11-04', self::NEXT_DAY)->needs_review);
        $this->assertSame(['not_delivery_day' => 'Not a delivery day for Next-day area'], $this->order('2026-11-07', self::NEXT_DAY, ref: 'o-2')->review_notes);
    }

    public function test_a_new_date_before_picking_moves_the_order_to_the_new_run()
    {
        $order = $this->order('2026-11-09');
        $firstRun = $order->delivery_run_id;

        $order = $this->order('2026-11-16', version: 2);

        $this->assertSame('2026-11-16', $order->delivery_date->toDateString());
        $this->assertNotSame($firstRun, $order->delivery_run_id);
        $this->assertSame('2026-11-16', $this->runDate($order));
        $this->assertFalse($order->needs_review);
    }

    public function test_a_new_date_after_picking_starts_stays_and_flags_both_dates()
    {
        $order = $this->order('2026-11-09');
        $this->pick($order);
        $run = $order->fresh()->delivery_run_id;

        $order = $this->order('2026-11-16', version: 2);

        $this->assertSame('2026-11-09', $order->delivery_date->toDateString());
        $this->assertSame($run, $order->delivery_run_id);
        $this->assertStringContainsString('from 2026-11-09 to 2026-11-16', $order->review_notes['date_changed']);
    }

    public function test_a_packed_order_is_on_a_pick_list_too_and_stays()
    {
        $order = $this->order('2026-11-09');
        $list = $this->pick($order);
        $line = $list->lines()->first();
        app(PickPackService::class)->recordLine($line, $this->manager, $line->qty_to_pick_base, null, [], 'scanner_fault');
        app(PickPackService::class)->saveProgress($list, $this->manager, [$line->id], []);
        app(PickPackService::class)->pack($list, $order, $this->manager, 1);

        $order = $this->order('2026-11-16', version: 2);

        $this->assertSame('2026-11-09', $order->delivery_date->toDateString());
        $this->assertArrayHasKey('date_changed', $order->review_notes);
    }

    public function test_a_cancelled_pick_list_lets_the_order_move_again()
    {
        $order = $this->order('2026-11-09');
        app(PickListService::class)->cancel($this->pick($order));

        $order = $this->order('2026-11-16', version: 2);

        $this->assertSame('2026-11-16', $order->delivery_date->toDateString());
    }

    public function test_a_run_set_by_hand_stays_and_flags_a_new_source_date()
    {
        $order = $this->order('2026-11-09');
        app(DeliveryScheduler::class)->assignManually($order, DeliveryArea::where('code', 'NEXT')->first(), Carbon::parse('2026-11-10', 'Australia/Brisbane'));

        $order = $this->order('2026-11-16', version: 2);

        $this->assertSame('2026-11-10', $order->delivery_date->toDateString());
        $this->assertStringContainsString('run was set by hand', $order->review_notes['date_changed']);
    }

    public function test_a_manager_changes_the_date_and_a_resend_of_the_old_date_leaves_it()
    {
        $order = $this->order('2026-11-09');

        $this->actingAs($this->manager)->put(route('orders.delivery-date', $order), ['delivery_date' => '2026-11-16'])->assertSessionHas('success');

        $order->refresh();
        $this->assertSame('2026-11-16', $this->runDate($order));
        $this->assertSame('manager', $order->delivery_date_origin);
        $log = AuditLog::where('event', 'order_delivery_date_changed')->sole();
        $this->assertSame([['delivery_date' => '2026-11-09'], ['delivery_date' => '2026-11-16']], [$log->old_values, $log->new_values]);
        $this->assertSame($this->manager->id, $log->user_id);

        $this->assertSame('2026-11-16', $this->order('2026-11-09', version: 2)->delivery_date->toDateString(), 'Same source date: nothing new');
        $this->assertSame('2026-11-23', $this->order('2026-11-23', version: 3)->delivery_date->toDateString(), 'A new source date moves it');
    }

    public function test_a_manager_change_on_a_picking_order_holds_flags_and_audits()
    {
        $order = $this->order('2026-11-09');
        $this->pick($order);

        $this->actingAs($this->manager)->put(route('orders.delivery-date', $order), ['delivery_date' => '2026-11-16'])->assertSessionHas('error');

        $order->refresh();
        $this->assertSame('2026-11-09', $order->delivery_date->toDateString());
        $this->assertStringContainsString('from 2026-11-09 to 2026-11-16', $order->review_notes['date_changed']);
        $this->assertSame(['requested' => '2026-11-16'], AuditLog::where('event', 'order_delivery_date_change_held')->sole()->meta);
    }

    public function test_staff_cant_change_a_date()
    {
        $this->actingAs($this->userWithRole(Permissions::ROLE_WAREHOUSE_STAFF))
            ->put(route('orders.delivery-date', $this->order('2026-11-09')), ['delivery_date' => '2026-11-16'])->assertForbidden();
    }

    public function test_setting_a_run_by_hand_cant_change_the_day_of_an_order_being_picked()
    {
        $order = $this->order('2026-11-09');
        $this->pick($order);

        $this->actingAs($this->manager)->put(route('orders.run', $order), ['delivery_area_id' => DeliveryArea::first()->id, 'run_date' => '2026-11-16'])->assertSessionHasErrors('run');
    }

    public function test_a_flag_marked_handled_isnt_raised_again_by_the_same_reason()
    {
        $order = $this->order('2026-11-04');
        $this->actingAs($this->manager)->post(route('orders.clear-flags', $order));

        $order = $this->order('2026-11-04', version: 2);

        $this->assertFalse($order->needs_review);
    }

    public function test_a_pick_list_never_mixes_days()
    {
        $a = $this->order('2026-11-09');
        $b = $this->order('2026-11-16', ref: 'o-2');

        $this->expectException(InvalidMovement::class);
        $this->expectExceptionMessage('one delivery day');
        app(PickListService::class)->create($this->manager, $this->site(), [$a->id, $b->id]);
    }

    public function test_pick_new_orders_only_collects_its_own_day_and_run()
    {
        $today = $this->order('2026-11-09');
        $sameDayOtherRun = $this->order('2026-11-09', self::NEXT_DAY, ref: 'o-2');
        $otherDay = $this->order('2026-11-16', ref: 'o-3');

        $list = $this->pick($today);

        $this->assertSame([$today->id], $list->lines()->with('orderLine')->get()->pluck('orderLine.order_id')->unique()->values()->all());
        $this->assertSame('open', $sameDayOtherRun->fresh()->status->value);
        $this->assertSame('open', $otherDay->fresh()->status->value);
    }

    public function test_the_queue_gives_overdue_runs_first_by_day_then_run_and_drops_finished_past_runs()
    {
        $this->order('2026-11-03', self::NEXT_DAY, ref: 'o-1');
        $this->order('2026-11-02', ref: 'o-2');
        $this->order('2026-11-02', self::NEXT_DAY, ref: 'o-3');
        $overdue = $this->order('2026-10-26', ref: 'o-4');
        $done = $this->order('2026-10-27', self::NEXT_DAY, ref: 'o-5');
        app(OrderService::class)->cancel($done, $this->manager, 'test');

        $this->actingAs($this->manager)->get(route('pick.queue'))->assertInertia(fn (AssertableInertia $page) => $page
            ->component('warehouse/PickQueue')
            ->where('today', '2026-11-02')
            ->where('runs', fn ($runs) => collect($runs)->map(fn ($r) => [$r['date'], $r['area']])->all() === [
                ['2026-10-26', 'Monday area'],
                ['2026-11-02', 'Monday area'],
                ['2026-11-02', 'Next-day area'],
                ['2026-11-03', 'Next-day area'],
            ])
            ->where('runs.0.orders.0.channel', 'ordermentum')
            ->where('runs.0.orders.0.number', $overdue->number));
    }

    public function test_orders_filter_by_delivery_date_and_source_and_sort_by_day()
    {
        $later = $this->order('2026-11-16');
        $sooner = $this->order('2026-11-09', channel: OrderChannel::Portal, ref: 'p-1');

        $this->actingAs($this->manager)->get(route('orders.index'))->assertInertia(fn (AssertableInertia $p) => $p
            ->where('orders.data', fn ($rows) => collect($rows)->pluck('id')->all() === [$sooner->id, $later->id])->where('today', '2026-11-02'));
        $this->actingAs($this->manager)->get(route('orders.index', ['delivery_date' => '2026-11-16']))->assertInertia(fn (AssertableInertia $p) => $p
            ->where('orders.data', fn ($rows) => collect($rows)->pluck('id')->all() === [$later->id]));
        $this->actingAs($this->manager)->get(route('orders.index', ['channel' => 'portal']))->assertInertia(fn (AssertableInertia $p) => $p
            ->where('orders.data', fn ($rows) => collect($rows)->pluck('channel')->all() === ['portal']));
    }

    public function test_the_pick_lists_page_shows_open_lists_by_day_and_history_apart()
    {
        $later = $this->pick($this->order('2026-11-16'));
        $sooner = $this->pick($this->order('2026-11-09', ref: 'o-2'));
        $dispatched = $this->pick($this->order('2026-10-26', ref: 'o-3'));
        app(PickListService::class)->cancel($dispatched);

        $this->actingAs($this->manager)->get(route('pick-lists.index'))->assertInertia(fn (AssertableInertia $p) => $p
            ->where('open', fn ($rows) => collect($rows)->map(fn ($r) => [$r['id'], $r['delivery_date']])->all() === [[$sooner->id, '2026-11-09'], [$later->id, '2026-11-16']]));
        $this->actingAs($this->manager)->get(route('pick-lists.index', ['view' => 'history']))->assertInertia(fn (AssertableInertia $p) => $p->where('history.data.0.id', $dispatched->id));
    }

    private function legacy(Order $order, array $values): Order
    {
        Order::whereKey($order->id)->update($values);

        return $order->fresh();
    }

    public function test_the_backfill_dry_run_shows_the_moves_and_changes_nothing()
    {
        $wrong = $this->order('2026-11-09', channel: OrderChannel::Portal, ref: 'p-1');
        $this->legacy($wrong, ['delivery_date' => '2026-11-16', 'delivery_run_id' => null]);
        $this->order('2026-11-09', channel: OrderChannel::Portal, ref: 'p-2');
        $before = Order::orderBy('id')->get(['id', 'delivery_date', 'delivery_run_id', 'review_notes'])->toArray();
        $audits = AuditLog::count();

        $this->artisan('orders:backfill-delivery-dates', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run: nothing was saved.')
            ->expectsOutputToContain('Moves')
            ->assertSuccessful();

        $this->assertSame($before, Order::orderBy('id')->get(['id', 'delivery_date', 'delivery_run_id', 'review_notes'])->toArray());
        $this->assertSame($audits, AuditLog::count());
    }

    public function test_the_backfill_needs_yes_applies_moves_and_audits_them()
    {
        $wrong = $this->order('2026-11-09', channel: OrderChannel::Portal, ref: 'p-1');
        $this->legacy($wrong, ['delivery_date' => '2026-11-16']);
        $right = $this->order('2026-11-09', channel: OrderChannel::Portal, ref: 'p-2');
        $onList = $this->order('2026-11-09', self::NEXT_DAY, channel: OrderChannel::Portal, ref: 'p-3');
        $this->pick($onList);
        $this->legacy($onList, ['delivery_date' => '2026-11-16']);

        $this->artisan('orders:backfill-delivery-dates')->expectsOutputToContain('--yes')->assertFailed();
        $this->assertSame('2026-11-16', $wrong->fresh()->delivery_date->toDateString());

        $this->artisan('orders:backfill-delivery-dates', ['--yes' => true])->assertSuccessful();

        $this->assertSame('2026-11-09', $wrong->fresh()->delivery_date->toDateString());
        $this->assertSame('2026-11-16', $onList->fresh()->delivery_date->toDateString(), 'On a pick list: left alone');
        $this->assertSame('2026-11-09', $right->fresh()->delivery_date->toDateString());
        $log = AuditLog::where('event', 'order_delivery_date_backfilled')->sole();
        $this->assertSame($wrong->id, (int) $log->auditable_id);
        $this->assertSame(['delivery_date' => '2026-11-16'], $log->old_values);
    }

    public function test_the_backfill_waits_out_a_rate_limit_instead_of_giving_up_on_the_order()
    {
        Sleep::fake();
        config(['services.ordermentum.api_key' => 'k', 'services.ordermentum.base_url' => 'https://api.om.test']);
        Http::fake(['api.om.test/v1/orders/o-1*' => Http::sequence()
            ->push('', 429, ['Retry-After' => '120'])
            ->push(['id' => 'o-1', 'deliveryDate' => '2026-11-15T14:00:00.000Z'])]);
        $order = $this->order('2026-11-09');

        $this->artisan('orders:backfill-delivery-dates', ['--yes' => true])->assertSuccessful();

        $this->assertSame('2026-11-16', $order->fresh()->delivery_date->toDateString());
    }

    public function test_the_backfill_reads_ordermentum_again_and_only_reads()
    {
        config(['services.ordermentum.api_key' => 'k', 'services.ordermentum.base_url' => 'https://api.om.test']);
        Http::fake(['api.om.test/v1/orders/o-1*' => Http::response(['id' => 'o-1', 'deliveryDate' => '2026-11-15T14:00:00.000Z'])]);
        $order = $this->order('2026-11-09');

        $this->artisan('orders:backfill-delivery-dates', ['--yes' => true])->assertSuccessful();

        $this->assertSame('2026-11-16', $order->fresh()->delivery_date->toDateString());
        Http::assertSent(fn ($r) => $r->method() === 'GET');
    }
}
