<?php

namespace Tests\Feature\Integrations;

use App\Authorization\Permissions;
use App\Integrations\Portal\PortalClient;
use App\Integrations\Portal\PortalSignature;
use App\Jobs\SendPortalCancelEvent;
use App\Jobs\SendPortalOrderEvent;
use App\Models\ChannelListing;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Product;
use App\Models\User;
use App\Services\Orders\OrderService;
use App\Services\Orders\PickListService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Tests\Support\StockFixtures;
use Tests\TestCase;

/**
 * Spec §6: a PUT's qty is what the customer ordered. What BWMS staff cancelled
 * (Cancel line qty) stays cancelled when the portal re-sends the order, e.g.
 * to say it's paid. Before this, every PUT reset qty_cancelled_base to the
 * substituted quantity and put the cancelled part back on the order.
 */
class PortalPutKeepsCancellationsTest extends TestCase
{
    use RefreshDatabase, StockFixtures;

    private User $manager;

    private Product $product;

    private int $version = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-09 09:00:00', 'Australia/Brisbane'));
        Bus::fake([SendPortalCancelEvent::class, SendPortalOrderEvent::class]);
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

    /** 3 cartons, held until paid like a Stripe order. */
    private function order(): Order
    {
        $this->signed('POST', '/api/portal/v1/reservations', [
            'order_ref' => 'ORD-A', 'customer' => ['portal_user_id' => 77], 'requested_delivery_date' => '2026-11-10',
            'payment_status' => 'awaiting_payment', 'pay_by' => '2026-11-09T00:00:00Z',
            'lines' => [['line_ref' => 'L1', 'portal_product_id' => 501, 'qty' => 3]],
        ], 'r:ORD-A')->assertCreated();

        return Order::sole();
    }

    /** The portal's full-state PUT, as it sends "paid". */
    private function sendPut(int $qty)
    {
        $v = ++$this->version;

        return $this->signed('PUT', '/api/portal/v1/orders/ORD-A', [
            'version' => $v, 'status' => 'confirmed', 'payment_status' => 'paid',
            'customer' => ['portal_user_id' => 77], 'requested_delivery_date' => '2026-11-10',
            'lines' => [['line_ref' => 'L1', 'portal_product_id' => 501, 'qty' => $qty]],
        ], "portal:order:ORD-A:v{$v}");
    }

    private function line(): OrderLine
    {
        return OrderLine::sole();
    }

    private function cancelCartons(int $cartons): void
    {
        app(OrderService::class)->cancelLineQty($this->line(), $cartons * 12, $this->manager, 'Oversold');
    }

    public function test_a_paid_put_keeps_a_staff_line_cancel(): void
    {
        $order = $this->order();
        $this->cancelCartons(2);

        $this->sendPut(3)->assertOk()->assertJsonPath('lines.0.reserved_qty', 1);

        $line = $this->line();
        $this->assertSame(36, $line->qty_base, 'the ordered quantity');
        $this->assertSame(24, $line->qty_cancelled_base, 'still cancelled');
        $this->assertSame(12, $line->outstandingBase());
        $this->assertSame(12, (int) $line->reservation?->qty_base);
        $this->assertFalse((bool) $order->fresh()->needs_review);
        $this->assertFalse((bool) $order->fresh()->payment_hold, 'paid still lifts the hold');
    }

    public function test_repeated_puts_never_lower_what_bwms_cancelled(): void
    {
        $this->order();
        $this->cancelCartons(1);

        $this->sendPut(3)->assertOk();
        $this->sendPut(3)->assertOk();
        $this->cancelCartons(1);
        $this->sendPut(3)->assertOk();

        $this->assertSame(24, $this->line()->qty_cancelled_base);
        $this->assertSame(12, $this->line()->outstandingBase());
    }

    public function test_a_raised_qty_adds_to_what_is_left(): void
    {
        $this->order();
        $this->cancelCartons(2);

        $this->sendPut(5)->assertOk();

        $line = $this->line();
        $this->assertSame(60, $line->qty_base);
        $this->assertSame(24, $line->qty_cancelled_base);
        $this->assertSame(36, $line->outstandingBase());
    }

    public function test_a_qty_down_to_exactly_what_was_cancelled_leaves_nothing_and_isnt_flagged(): void
    {
        $order = $this->order();
        $this->cancelCartons(2);

        $this->sendPut(2)->assertOk();

        $this->assertSame(0, $this->line()->outstandingBase());
        $this->assertSame(24, $this->line()->qty_cancelled_base);
        $this->assertFalse((bool) $order->fresh()->needs_review);
    }

    public function test_a_qty_below_what_bwms_cancelled_keeps_the_cancel_and_is_flagged(): void
    {
        $order = $this->order();
        $this->cancelCartons(2);

        $this->sendPut(1)->assertOk();

        $line = $this->line();
        $this->assertSame(24, $line->qty_cancelled_base, 'never lowered');
        $this->assertSame(24, $line->qty_base, 'floored at dispatched + cancelled');
        $this->assertSame(0, $line->outstandingBase());
        $order->refresh();
        $this->assertTrue($order->needs_review);
        $this->assertArrayHasKey('reduced_below_cancelled', $order->review_notes);
        $this->assertStringContainsString('MILK-1 as 1, less than the 0 dispatched and 2 cancelled', $order->review_notes['reduced_below_cancelled']);
        $this->assertArrayNotHasKey('reduced_below_dispatched', $order->review_notes);
    }

    public function test_the_floor_counts_what_was_dispatched_too(): void
    {
        $order = $this->order();
        $order->forceFill(['short_ship_policy' => 'backorder', 'payment_hold' => false])->save();
        $picker = $this->userWithRole(Permissions::ROLE_WAREHOUSE_STAFF);
        $list = app(PickListService::class)->create($picker, $this->site(), [$order->id])['pickList'];
        app(PickListService::class)->recordPicked($list, [$list->lines()->value('id') => 12]); // 1 of 3 cartons
        app(PickListService::class)->dispatch($list, $picker);
        $this->cancelCartons(1);

        $this->sendPut(3)->assertOk();
        $this->assertSame(12, $this->line()->outstandingBase(), '3 ordered − 1 dispatched − 1 cancelled');
        $this->assertFalse((bool) $order->fresh()->needs_review);

        $this->sendPut(1)->assertOk();
        $line = $this->line();
        $this->assertSame(24, $line->qty_base);
        $this->assertSame(12, $line->qty_cancelled_base);
        $this->assertSame(0, $line->outstandingBase());
        $this->assertArrayHasKey('reduced_below_cancelled', $order->fresh()->review_notes);
    }
}
