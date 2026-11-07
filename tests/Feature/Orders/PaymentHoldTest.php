<?php

namespace Tests\Feature\Orders;

use App\Authorization\Permissions;
use App\Integrations\Portal\PortalSignature;
use App\Models\AuditLog;
use App\Models\ChannelListing;
use App\Models\DeliveryArea;
use App\Models\Order;
use App\Models\Product;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Orders\AvailabilityService;
use App\Services\Orders\PickListService;
use App\Services\Orders\PickPackService;
use App\Services\Stock\Exceptions\InvalidMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\Support\StockFixtures;
use Tests\TestCase;

class PaymentHoldTest extends TestCase
{
    use RefreshDatabase, StockFixtures;

    private const PAY_BY = '2026-11-12T07:00:00Z'; // 5pm Thursday in Brisbane

    private User $manager;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-09 09:00', 'Australia/Brisbane')); // Monday
        $this->seedStockReferenceData();
        $this->manager = $this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER);
        config(['bwms.portal' => array_merge(config('bwms.portal'), ['api_enabled' => true, 'inbound_keys' => ['k1' => 's1']])]);
        $this->product = Product::factory()->create(['sku' => 'MILK-1']);
        ChannelListing::create(['channel' => 'portal', 'product_id' => $this->product->id, 'product_unit_id' => $this->product->units()->value('id'), 'external_product_id' => '501', 'external_variant_id' => '501']);
        $this->stockIn($this->product, 60);
        DeliveryArea::create(['code' => 'N', 'name' => 'North', 'weekdays' => [1, 2, 3, 4, 5], 'is_active' => true, 'sort_order' => 1])->places()->create(['postcode' => '4500', 'suburb' => '']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function send(string $method, string $uri, array $body, string $key)
    {
        $raw = json_encode($body);
        $ts = (string) time();

        return $this->call($method, $uri, [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => $key,
            'HTTP_X_BWMS_KEY_ID' => 'k1', 'HTTP_X_BWMS_TIMESTAMP' => $ts, 'HTTP_X_BWMS_SIGNATURE' => PortalSignature::sign($ts, $raw, 's1'),
        ], $raw);
    }

    private function body(array $extra = []): array
    {
        return $extra + ['customer' => ['portal_user_id' => 77, 'business_name' => 'Cafe'], 'requested_delivery_date' => '2026-11-10', 'delivery' => ['postcode' => '4500'],
            'lines' => [['line_ref' => '501', 'portal_product_id' => 501, 'qty' => 2]]];
    }

    private function reserve(array $extra = ['payment_status' => 'awaiting_payment', 'pay_by' => self::PAY_BY], string $ref = 'ORD-A')
    {
        return $this->send('POST', '/api/portal/v1/reservations', $this->body($extra + ['order_ref' => $ref]), "r:{$ref}");
    }

    private function update(int $version, array $extra = [], string $status = 'confirmed', string $ref = 'ORD-A')
    {
        return $this->send('PUT', "/api/portal/v1/orders/{$ref}", $this->body($extra + ['version' => $version, 'status' => $status]), "u:{$ref}:{$version}");
    }

    public function test_an_awaiting_payment_order_reserves_stock_and_is_held()
    {
        $this->reserve()->assertCreated()->assertJsonPath('payment_hold', true)->assertJsonPath('lines.0.reserved_qty', 2);

        $order = Order::sole();
        $this->assertTrue($order->payment_hold);
        $this->assertSame('2026-11-12', $order->holdSummary()['pay_by']);
        $this->assertSame(2, (int) Reservation::sum('qty_base'));
        $this->assertSame(58, app(AvailabilityService::class)->available($this->product));
    }

    public function test_a_paid_or_silent_order_isnt_held()
    {
        $this->reserve(['payment_status' => 'paid'], 'ORD-P')->assertCreated()->assertJsonMissingPath('payment_hold');
        $this->reserve([], 'ORD-N')->assertCreated();

        $this->assertSame(0, Order::where('payment_hold', true)->count());
    }

    public function test_a_held_order_stays_out_of_pick_n_new_orders()
    {
        $this->reserve();
        $this->reserve([], 'ORD-READY');
        $run = Order::first()->deliveryRun;

        $this->assertSame(['ORD-READY'], app(PickPackService::class)->waitingOrders($run)->pluck('external_ref')->all());

        $list = app(PickPackService::class)->startNew($run, $this->manager);

        $this->assertSame('picking', Order::where('external_ref', 'ORD-READY')->sole()->status->value);
        $this->assertSame('open', Order::where('external_ref', 'ORD-A')->sole()->status->value);
        $this->assertSame(1, $list->lines()->count());
    }

    public function test_a_run_of_only_held_orders_offers_nothing_to_pick()
    {
        $this->reserve();
        $run = Order::sole()->deliveryRun;

        $this->expectException(InvalidMovement::class);
        app(PickPackService::class)->startNew($run, $this->manager);
    }

    public function test_the_desktop_route_refuses_a_held_order_too()
    {
        $this->reserve();

        $this->expectException(InvalidMovement::class);
        $this->expectExceptionMessage('waiting for payment');
        app(PickListService::class)->create($this->manager, $this->site(), [Order::sole()->id]);
    }

    public function test_paid_releases_the_hold_and_the_order_can_be_picked()
    {
        $this->reserve();

        $this->update(1, ['payment_status' => 'paid'])->assertOk()->assertJsonMissingPath('payment_hold');

        $order = Order::sole();
        $this->assertFalse($order->payment_hold);
        $this->assertSame(1, app(PickPackService::class)->waitingOrders($order->deliveryRun)->count());
        $this->assertSame('order_payment_received', AuditLog::where('auditable_id', $order->id)->where('event', 'order_payment_received')->sole()->event);
    }

    public function test_an_update_that_doesnt_mention_payment_changes_nothing()
    {
        $this->reserve();

        $this->update(1)->assertOk()->assertJsonPath('payment_hold', true);

        $this->assertTrue(Order::sole()->payment_hold);
    }

    public function test_the_portal_can_move_the_pay_by_date()
    {
        $this->reserve();

        $this->update(1, ['payment_status' => 'awaiting_payment', 'pay_by' => '2026-11-20T07:00:00Z'])->assertOk();

        $this->assertSame('2026-11-20', Order::sole()->holdSummary()['pay_by']);
    }

    public function test_cancelling_at_pay_by_releases_the_stock()
    {
        $this->reserve();

        $this->update(1, ['lines' => []], 'cancelled')->assertOk()->assertJsonPath('status', 'cancelled');

        $this->assertSame(60, app(AvailabilityService::class)->available($this->product));
    }

    public function test_a_manager_releases_a_hold_by_hand_and_it_is_audited()
    {
        $this->reserve();
        $order = Order::sole();

        $this->actingAs($this->manager)->post(route('orders.release-hold', $order))->assertSessionHas('success');

        $order->refresh();
        $this->assertFalse($order->payment_hold);
        $this->assertSame($this->manager->id, $order->hold_released_by);
        $this->assertNotNull($order->hold_released_at);
        $log = AuditLog::where('event', 'order_hold_released')->sole();
        $this->assertSame($this->manager->id, $log->user_id);
        $this->assertSame('2026-11-12', $log->meta['pay_by']);
    }

    public function test_a_hand_release_survives_the_portal_resending_awaiting_payment()
    {
        $this->reserve();
        $this->actingAs($this->manager)->post(route('orders.release-hold', Order::sole()));

        $this->update(1, ['payment_status' => 'awaiting_payment'])->assertOk()->assertJsonMissingPath('payment_hold');

        $this->assertFalse(Order::sole()->payment_hold);
    }

    public function test_staff_cant_release_a_hold_and_there_must_be_one_to_release()
    {
        $this->reserve();
        $order = Order::sole();

        $this->actingAs($this->userWithRole(Permissions::ROLE_WAREHOUSE_STAFF))->post(route('orders.release-hold', $order))->assertForbidden();

        $this->actingAs($this->manager)->post(route('orders.release-hold', $order));
        $this->actingAs($this->manager)->post(route('orders.release-hold', $order))->assertSessionHasErrors('order');
    }

    public function test_the_badge_data_reaches_the_order_the_list_the_queue_and_the_manifest()
    {
        $this->reserve();
        $order = Order::sole();
        $hold = ['pay_by' => '2026-11-12', 'overdue' => false];

        $this->actingAs($this->manager)->get(route('orders.show', $order))->assertInertia(fn (AssertableInertia $p) => $p->where('order.hold', $hold)->where('can.release_hold', true));
        $this->actingAs($this->manager)->get(route('orders.index'))->assertInertia(fn (AssertableInertia $p) => $p->where('orders.data.0.hold', $hold));
        $this->actingAs($this->manager)->get(route('pick.manifest', $order->deliveryRun))->assertInertia(fn (AssertableInertia $p) => $p->where('orders.0.hold', $hold));
        $this->actingAs($this->manager)->get(route('pick.queue'))->assertInertia(fn (AssertableInertia $p) => $p
            ->where('runs.0.held', 1)->where('runs.0.waiting', 0)->where('runs.0.orders.0.hold', $hold));
    }

    public function test_a_missed_pay_by_shows_as_overdue_and_stays_held_until_someone_decides()
    {
        $this->reserve();
        $this->travelTo(Carbon::parse('2026-11-13 09:00', 'Australia/Brisbane'));

        $order = Order::sole();
        $this->assertTrue($order->holdSummary()['overdue']);
        $this->assertTrue($order->payment_hold);
    }
}
