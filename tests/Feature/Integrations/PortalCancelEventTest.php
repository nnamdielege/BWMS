<?php

namespace Tests\Feature\Integrations;

use App\Authorization\Permissions;
use App\Integrations\Portal\PortalClient;
use App\Integrations\Portal\PortalSignature;
use App\Integrations\Portal\PortalUnavailable;
use App\Jobs\SendPortalCancelEvent;
use App\Jobs\SendPortalOrderEvent;
use App\Models\ChannelListing;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\SyncAttempt;
use App\Models\User;
use App\Services\Orders\OrderService;
use App\Services\Orders\PickListService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\StockFixtures;
use Tests\TestCase;

/** Staff cancelling a portal order in BWMS has to reach the portal, or it never credits the customer. */
class PortalCancelEventTest extends TestCase
{
    use RefreshDatabase, StockFixtures;

    private User $manager;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-09 09:00:00', 'Australia/Brisbane'));
        $this->seedStockReferenceData();
        $this->manager = $this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER);
        config(['bwms.portal' => array_merge(config('bwms.portal'), [
            'api_enabled' => true, 'inbound_keys' => ['k1' => 's1'], 'events_enabled' => true,
            'base_url' => 'https://portal.test', 'outbound_key_id' => 'bwms', 'outbound_secret' => 'out-secret',
        ])]);
        $this->app->forgetInstance(PortalClient::class);
        // Portal product 501 is sold by the carton of 12.
        $this->product = Product::factory()->withPack('Carton', 12)->create(['sku' => 'MILK-1']);
        ChannelListing::create(['channel' => 'portal', 'product_id' => $this->product->id, 'product_unit_id' => $this->product->units()->where('base_qty', 12)->value('id'), 'external_product_id' => '501', 'external_variant_id' => '501']);
        $this->stockIn($this->product, 240);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function signed(string $method, string $uri, array $body, string $key)
    {
        $raw = json_encode($body);
        $ts = (string) time();

        return $this->call($method, $uri, [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => $key,
            'HTTP_X_BWMS_KEY_ID' => 'k1', 'HTTP_X_BWMS_TIMESTAMP' => $ts, 'HTTP_X_BWMS_SIGNATURE' => PortalSignature::sign($ts, $raw, 's1'),
        ], $raw);
    }

    private function order(array $lines = [['line_ref' => 'L1', 'portal_product_id' => 501, 'qty' => 3]], array $extra = []): Order
    {
        $this->signed('POST', '/api/portal/v1/reservations', $extra + ['order_ref' => 'ORD-A', 'customer' => ['portal_user_id' => 77], 'requested_delivery_date' => '2026-11-10', 'lines' => $lines], 'r:ORD-A')->assertCreated();

        return Order::sole();
    }

    private function cancelInBwms(Order $order)
    {
        return $this->actingAs($this->manager)->post(route('orders.cancel', $order), ['reason' => 'No stock'])->assertSessionHas('success');
    }

    public function test_cancelling_in_bwms_tells_the_portal_what_was_cancelled()
    {
        Bus::fake([SendPortalCancelEvent::class]);
        $order = $this->order();

        $this->cancelInBwms($order);

        Bus::assertDispatched(SendPortalCancelEvent::class, fn ($job) => $job->orderId === $order->id
            && $job->lines === [['line_ref' => 'L1', 'qty' => 3]] && $job->reason === 'No stock');
    }

    public function test_the_event_is_signed_keyed_and_in_sell_units()
    {
        $sent = [];
        Http::fake(['portal.test/*' => function (Request $r) use (&$sent) {
            $sent[] = $r;

            return Http::response(['ok' => true]);
        }]);
        $order = $this->order();

        $this->cancelInBwms($order);

        $this->assertCount(1, $sent);
        $request = $sent[0];
        $this->assertSame('https://portal.test/api/bwms/v1/order-events', $request->url());
        $this->assertSame([
            'order_ref' => 'ORD-A', 'event' => 'cancelled', 'reason' => 'No stock',
            'lines' => [['line_ref' => 'L1', 'qty' => 3]], 'at' => '2026-11-08T23:00:00Z',
        ], $request->data());
        $this->assertSame('bwms:order-event:ORD-A:cancelled', $request->header('Idempotency-Key')[0]);
        $this->assertNotEmpty($request->header('X-BWMS-Signature')[0]);
        $this->assertSame(1, SyncAttempt::where('operation', 'order_event')->where('success', true)->count());
    }

    public function test_cancel_rest_after_a_short_dispatch_sends_only_what_was_left()
    {
        Bus::fake([SendPortalCancelEvent::class, SendPortalOrderEvent::class]);
        $order = $this->order();
        $order->forceFill(['short_ship_policy' => 'backorder'])->save(); // an order from before portal orders cancelled the rest
        $picker = $this->userWithRole(Permissions::ROLE_WAREHOUSE_STAFF);
        $list = app(PickListService::class)->create($picker, $this->site(), [$order->id])['pickList'];
        app(PickListService::class)->recordPicked($list, [$list->lines()->value('id') => 12]); // 1 of 3 cartons
        app(PickListService::class)->dispatch($list, $picker);

        $this->cancelInBwms($order);

        Bus::assertDispatched(SendPortalCancelEvent::class, fn ($job) => $job->lines === [['line_ref' => 'L1', 'qty' => 2]]);
    }

    public function test_a_partly_dispatched_pack_is_sent_as_a_fraction()
    {
        Bus::fake([SendPortalCancelEvent::class]);
        $order = $this->order();
        $order->lines()->sole()->forceFill(['qty_dispatched_base' => 6])->save();

        $this->cancelInBwms($order);

        Bus::assertDispatched(SendPortalCancelEvent::class, fn ($job) => $job->lines === [['line_ref' => 'L1', 'qty' => 2.5]]);
    }

    public function test_the_portals_own_cancel_isnt_echoed_back()
    {
        Bus::fake([SendPortalCancelEvent::class]);
        $this->order();

        $this->signed('PUT', '/api/portal/v1/orders/ORD-A', ['version' => 1, 'status' => 'cancelled', 'lines' => []], 'u:1')->assertOk();

        Bus::assertNotDispatched(SendPortalCancelEvent::class);
    }

    public function test_the_automatic_cancel_at_dispatch_is_left_to_the_dispatch_event()
    {
        Bus::fake([SendPortalCancelEvent::class, SendPortalOrderEvent::class]);
        $order = $this->order();
        $picker = $this->userWithRole(Permissions::ROLE_WAREHOUSE_STAFF);
        $list = app(PickListService::class)->create($picker, $this->site(), [$order->id])['pickList'];
        app(PickListService::class)->recordPicked($list, [$list->lines()->value('id') => 12]);

        app(PickListService::class)->dispatch($list, $picker);

        Bus::assertNotDispatched(SendPortalCancelEvent::class);
        $this->assertSame(24, $order->lines()->sole()->qty_cancelled_base);
    }

    public function test_other_channels_send_nothing()
    {
        Bus::fake([SendPortalCancelEvent::class]);
        $order = app(OrderService::class)->createManual($this->manager, [
            'customer_id' => Customer::factory()->create()->id, 'site_id' => $this->site()->id, 'short_ship_policy' => 'backorder', 'requested_delivery_date' => '2026-11-10',
            'lines' => [['product_id' => $this->product->id, 'product_unit_id' => $this->product->units()->value('id'), 'qty' => 1]],
        ]);

        $this->cancelInBwms($order);

        Bus::assertNotDispatched(SendPortalCancelEvent::class);
    }

    public function test_nothing_is_sent_while_events_are_switched_off()
    {
        Bus::fake([SendPortalCancelEvent::class]);
        config(['bwms.portal.events_enabled' => false]);

        $this->cancelInBwms($this->order());

        Bus::assertNotDispatched(SendPortalCancelEvent::class);
    }

    public function test_a_failed_cancel_sends_nothing()
    {
        Bus::fake([SendPortalCancelEvent::class]);
        $order = $this->order();
        $order->forceFill(['status' => 'dispatched'])->save();

        $this->actingAs($this->manager)->post(route('orders.cancel', $order), ['reason' => 'x'])->assertSessionHasErrors('order');

        Bus::assertNotDispatched(SendPortalCancelEvent::class);
    }

    public function test_it_waits_for_the_commit_so_a_rolled_back_cancel_never_reaches_the_portal()
    {
        Bus::fake([SendPortalCancelEvent::class]);
        $order = $this->order();

        try {
            DB::transaction(function () use ($order) {
                app(OrderService::class)->cancel($order, $this->manager, 'No stock');
                throw new \RuntimeException('rolled back');
            });
        } catch (\RuntimeException) {
        }

        Bus::assertNotDispatched(SendPortalCancelEvent::class);
    }

    public function test_a_5xx_or_lost_connection_retries_with_the_same_schedule_as_dispatch_events()
    {
        Bus::fake([SendPortalCancelEvent::class]);
        $job = new SendPortalCancelEvent(1, [['line_ref' => 'L1', 'qty' => 1]], 'No stock', '2026-11-08T23:00:00Z');

        $this->assertSame([30, 60, 300, 900, 3600], $job->backoff);
        $this->assertSame(10, $job->maxExceptions);
        $this->assertEquals(now()->addDay(), $job->retryUntil());

        $order = $this->order();
        Http::fake(['portal.test/*' => Http::response('down', 503)]);
        $this->expectException(PortalUnavailable::class);
        (new SendPortalCancelEvent($order->id, [['line_ref' => 'L1', 'qty' => 1]], 'No stock', '2026-11-08T23:00:00Z'))->handle(app(PortalClient::class));
    }

    public function test_a_retry_says_exactly_what_the_cancel_said()
    {
        Http::fake(['portal.test/*' => Http::sequence()->push('down', 503)->push(['ok' => true])]);
        $order = $this->order();
        $job = new SendPortalCancelEvent($order->id, [['line_ref' => 'L1', 'qty' => 3]], 'No stock', '2026-11-08T23:00:00Z');

        try {
            $job->handle(app(PortalClient::class));
        } catch (PortalUnavailable) {
        }
        $order->lines()->sole()->forceFill(['qty_cancelled_base' => 0])->save(); // whatever happens to the order meanwhile
        $job->handle(app(PortalClient::class));

        $requests = Http::recorded();
        $this->assertSame($requests[0][0]->body(), $requests[1][0]->body());
        $this->assertSame($requests[0][0]->header('Idempotency-Key'), $requests[1][0]->header('Idempotency-Key'));
    }
}
