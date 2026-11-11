<?php

namespace Tests\Feature\Integrations;

use App\Authorization\Permissions;
use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Integrations\Ordermentum\OrderIngestor;
use App\Jobs\PushOrdermentumStock;
use App\Jobs\PushPortalStock;
use App\Models\AuditLog;
use App\Models\ChannelListing;
use App\Models\ChannelUnmappedVariant;
use App\Models\IntegrationInbox;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockMovement;
use App\Services\Orders\AvailabilityService;
use App\Services\Orders\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\OrderFixtures;
use Tests\Support\OrdermentumFixtures;
use Tests\Support\StockFixtures;
use Tests\TestCase;

/** ORDERMENTUM_INGEST_SINCE and orders:close-historical (old orders ingested 2026-11-08). */
class OrdermentumHistoricalOrdersTest extends TestCase
{
    use OrderFixtures, OrdermentumFixtures, RefreshDatabase, StockFixtures;

    private Product $milk;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-11 02:00:00'); // 12:00 in Brisbane
        $this->seedStockReferenceData();
        $this->configureOrdermentum(['push' => false, 'since' => '2026-11-06']);
        $this->milk = Product::factory()->create(['sku' => 'MILK']);
        $this->stockIn($this->milk, 100);
        $this->listing($this->milk, 'var-milk');
    }

    private function omOrder(string $id, string $deliveryDate, int $qty = 4, string $updatedAt = '2026-11-08T12:31:00.000Z', string $variant = 'var-milk'): array
    {
        return ['id' => $id, 'status' => 'Placed', 'retailerId' => 'ret-1', 'updatedAt' => $updatedAt,
            'deliveryDate' => $deliveryDate, 'lineItems' => [['id' => 'li-1', 'variantId' => $variant, 'quantity' => $qty]]];
    }

    private function inbox(string $orderId): IntegrationInbox
    {
        return IntegrationInbox::create(['channel' => 'ordermentum', 'event' => 'poll', 'external_id' => $orderId,
            'dedupe_key' => hash('sha256', $orderId.microtime()), 'payload' => '{}']);
    }

    private function available(): int
    {
        return app(AvailabilityService::class)->available($this->milk);
    }

    public function test_a_new_order_delivered_before_go_live_is_recorded_but_never_created()
    {
        // Delivered 2026-11-05 in Brisbane (14:00Z on the 4th is midnight on the 5th).
        Http::fake(['api.om.test/v1/orders/old-1*' => Http::response($this->omOrder('old-1', '2026-11-04T14:00:00.000Z'))]);
        $inbox = $this->inbox('old-1');

        $status = app(OrderIngestor::class)->ingest('old-1', $inbox);

        $this->assertSame('ignored_historical', $status);
        $this->assertSame('ignored_historical', $inbox->fresh()->status);
        $this->assertStringContainsString('Delivered 2026-11-05', $inbox->fresh()->error);
        $this->assertNotNull($inbox->fresh()->processed_at);
        $this->assertSame(0, Order::count());
        $this->assertSame(100, $this->available());
    }

    public function test_an_old_order_with_unmapped_variants_does_not_wait_for_mapping()
    {
        // Parking it as needs_mapping would bring it back on the next product import.
        Http::fake(['api.om.test/v1/orders/old-2*' => Http::response($this->omOrder('old-2', '2023-02-01', variant: 'var-gone'))]);

        $this->assertSame('ignored_historical', app(OrderIngestor::class)->ingest('old-2', $this->inbox('old-2')));
        $this->assertSame(0, ChannelUnmappedVariant::count());
    }

    public function test_orders_delivered_on_or_after_go_live_are_created_as_before()
    {
        Http::fake(['api.om.test/v1/orders/new-1*' => Http::response($this->omOrder('new-1', '2026-11-06'))]);

        $this->assertSame('processed', app(OrderIngestor::class)->ingest('new-1', $this->inbox('new-1')));
        $this->assertSame(96, $this->available());
    }

    public function test_an_order_already_in_bwms_still_takes_updates_whatever_its_date()
    {
        $this->channelOrder(OrderChannel::Ordermentum, 'old-3', 1, [[$this->milk, 4, 'li-1']], date: '2026-11-02');
        Http::fake(['api.om.test/v1/orders/old-3*' => Http::response($this->omOrder('old-3', '2026-11-02', qty: 7))]);

        $this->assertSame('processed', app(OrderIngestor::class)->ingest('old-3', $this->inbox('old-3')));
        $this->assertSame(7, Order::sole()->lines->first()->qty);
        $this->assertSame(93, $this->available());
    }

    public function test_no_cutoff_configured_means_no_guard()
    {
        config(['bwms.ordermentum.ingest_since' => null]);
        Http::fake(['api.om.test/v1/orders/old-4*' => Http::response($this->omOrder('old-4', '2022-09-01'))]);

        $this->assertSame('processed', app(OrderIngestor::class)->ingest('old-4', $this->inbox('old-4')));
    }

    /** @return array{old: Order, older: Order, picking: Order, missed: Order, upcoming: Order, manual: Order} */
    private function seedOpenOrders(): array
    {
        config(['bwms.ordermentum.ingest_since' => null]); // as on 2026-11-08, before the guard
        $orders = [
            'old' => $this->channelOrder(OrderChannel::Ordermentum, 'old-a', 1, [[$this->milk, 3]], date: '2026-11-05'),
            'older' => $this->channelOrder(OrderChannel::Ordermentum, 'old-b', 1, [[$this->milk, 5]], date: '2022-09-14'),
            'picking' => $this->channelOrder(OrderChannel::Ordermentum, 'old-c', 1, [[$this->milk, 2]], date: '2024-01-10'),
            'missed' => $this->channelOrder(OrderChannel::Ordermentum, 'missed', 1, [[$this->milk, 6]], date: '2026-11-07'),
            'upcoming' => $this->channelOrder(OrderChannel::Ordermentum, 'upcoming', 1, [[$this->milk, 1]], date: '2026-11-11'),
            'manual' => $this->manualOrder($this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER), $this->milk, 8),
        ];
        $orders['picking']->forceFill(['status' => OrderStatus::Picking])->save();
        $orders['manual']->forceFill(['delivery_date' => '2025-01-01'])->save();

        return $orders;
    }

    public function test_dry_run_lists_each_order_and_changes_nothing()
    {
        $o = $this->seedOpenOrders();
        $before = $this->available();

        $this->assertSame(0, Artisan::call('orders:close-historical', ['--before' => '2026-11-06', '--dry-run' => true]));
        [$historical, $pending] = explode('and never picked', Artisan::output());

        $this->assertStringContainsString('2 open Ordermentum order(s) delivered before 2026-11-06 would be cancelled', $historical);
        $unit = $this->milk->units()->where('base_qty', 1)->value('name');
        $this->assertMatchesRegularExpression('/'.preg_quote($o['older']->number, '/').'\s*\|\s*-\s*\|\s*2022-09-14\s*\|\s*MILK ×5 '.preg_quote($unit, '/').'/u', $historical);
        $this->assertMatchesRegularExpression('/'.preg_quote($o['old']->number, '/').'\s*\|\s*-\s*\|\s*2026-11-05\s*\|\s*MILK ×3/u', $historical);
        $this->assertLessThan(strpos($historical, $o['old']->number), strpos($historical, $o['older']->number), 'Oldest first');
        foreach (['picking', 'missed', 'upcoming', 'manual'] as $key) {
            $this->assertStringNotContainsString($o[$key]->number, $historical, $key);
        }

        $this->assertStringContainsString('1 open Ordermentum order(s) delivered 2026-11-06 to 2026-11-10', $historical);
        $this->assertStringContainsString($o['missed']->number, $pending);
        $this->assertStringContainsString('2026-11-07', $pending);
        $this->assertStringNotContainsString($o['upcoming']->number, $pending);
        $this->assertStringContainsString('Dry run: nothing changed.', $pending);

        $this->assertSame(0, Order::where('status', OrderStatus::Cancelled)->count());
        $this->assertSame($before, $this->available());
    }

    public function test_cancels_old_open_orders_releasing_reservations_without_stock_moves_or_channel_sends()
    {
        $o = $this->seedOpenOrders();
        // Every outbound path switched on, so anything sent would show.
        config(['bwms.ordermentum.push_enabled' => true, 'bwms.portal.push_enabled' => true, 'bwms.portal.events_enabled' => true]);
        ChannelListing::create(['channel' => 'portal', 'product_id' => $this->milk->id, 'product_unit_id' => $this->milk->units()->value('id'),
            'external_variant_id' => 'p-milk', 'sync_enabled' => true]);
        ChannelListing::query()->update(['push_requested_at' => null]);
        Queue::fake();
        Http::fake();
        $movements = StockMovement::count();
        $audits = (int) AuditLog::max('id');
        $before = $this->available();

        $exit = Artisan::call('orders:close-historical', ['--before' => '2026-11-06']);
        $output = Artisan::output();
        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('Cancelled 2 order(s)', $output);

        foreach (['old', 'older'] as $key) {
            $order = $o[$key]->fresh();
            $this->assertSame(OrderStatus::Cancelled, $order->status);
            $this->assertSame('Historical order imported in error', $order->cancel_reason);
            $this->assertNull($order->cancelled_by);
            $this->assertSame(1, AuditLog::where('auditable_id', $order->id)->where('auditable_type', $order->getMorphClass())->where('id', '>', $audits)->count());
        }
        $this->assertSame(['order_cancelled', 'order_cancelled'], AuditLog::where('id', '>', $audits)->pluck('event')->all(), 'One audit entry per order');
        $this->assertSame('order_cancelled', AuditLog::latest('id')->value('event'));
        $this->assertSame($before + 8, $this->available(), 'Both reservations released');
        $this->assertSame($movements, StockMovement::count(), 'No stock moved');

        // Picking, after the cutoff, and manual orders are left alone.
        foreach (['picking', 'missed', 'upcoming', 'manual'] as $key) {
            $this->assertNotSame(OrderStatus::Cancelled, $o[$key]->fresh()->status, $key);
        }

        Queue::assertNothingPushed();
        Http::assertNothingSent();
        $this->assertSame(0, ChannelListing::whereNotNull('push_requested_at')->count(), 'No stock push requested');

        // Run again: nothing left to do.
        $this->artisan('orders:close-historical', ['--before' => '2026-11-06'])
            ->expectsOutputToContain('0 open Ordermentum order(s) delivered before 2026-11-06')->assertSuccessful();
    }

    public function test_cancel_by_staff_still_pushes_stock_outside_the_clean_up()
    {
        $order = $this->channelOrder(OrderChannel::Ordermentum, 'x', 1, [[$this->milk, 3]], date: '2026-11-12');
        config(['bwms.ordermentum.push_enabled' => true]);
        ChannelListing::query()->update(['push_requested_at' => null]);
        Queue::fake();

        app(OrderService::class)->cancel($order, null, 'test');

        Queue::assertPushed(PushOrdermentumStock::class);
        Queue::assertNotPushed(PushPortalStock::class); // portal push is off
    }

    public function test_before_is_required()
    {
        $this->artisan('orders:close-historical')->assertFailed();
        $this->artisan('orders:close-historical', ['--before' => '2026-13-01'])->assertFailed();
        Artisan::call('orders:close-historical', ['--before' => '2026-11-06', '--dry-run' => true]);
        $this->assertStringContainsString('(none)', Artisan::output());
    }
}
