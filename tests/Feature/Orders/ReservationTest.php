<?php

namespace Tests\Feature\Orders;

use App\Authorization\Permissions;
use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Events\OrderOversold;
use App\Events\StockChanged;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Orders\AvailabilityService;
use App\Services\Orders\Exceptions\InsufficientAvailability;
use App\Services\Orders\OrderService;
use App\Services\Stock\Exceptions\InvalidMovement;
use Database\Seeders\DefaultSiteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Support\OrderFixtures;
use Tests\Support\StockFixtures;
use Tests\TestCase;

class ReservationTest extends TestCase
{
    use OrderFixtures, RefreshDatabase, StockFixtures;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedStockReferenceData();
        $this->manager = $this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER);
    }

    private function available(Product $p, ?Location $site = null): int
    {
        return app(AvailabilityService::class)->available($p, $site);
    }

    public function test_order_reserves_and_available_is_on_hand_minus_reserved()
    {
        $p = Product::factory()->create();
        $this->stockIn($p, 10, $this->bin('STOCK'));

        $order = $this->manualOrder($this->manager, $p, 4);

        $this->assertSame(OrderStatus::Open, $order->status);
        $this->assertSame(4, $order->lines->first()->reservation->qty_base);
        $this->assertSame(6, $this->available($p));
        $this->assertSame(10, $this->balance($p, $this->bin('STOCK')), 'Reserving does not move stock (D-2).');
    }

    public function test_manual_and_portal_orders_are_refused_when_short_and_nothing_is_reserved()
    {
        $a = Product::factory()->create();
        $b = Product::factory()->create();
        $this->stockIn($a, 10, $this->bin('STOCK'));
        $this->stockIn($b, 1, $this->bin('STOCK'));

        try {
            $this->channelOrder(OrderChannel::Portal, 'P-100', 1, [[$a, 5, 'L1'], [$b, 3, 'L2']]);
            $this->fail('Expected InsufficientAvailability');
        } catch (InsufficientAvailability $e) {
            $this->assertSame([['line_ref' => 'L2', 'sku' => $b->sku, 'requested' => 3, 'available' => 1]], $e->lines);
        }

        $this->assertSame(0, Order::count(), 'All-or-nothing: the portal order must not exist.');
        $this->assertSame(10, $this->available($a));

        $this->expectException(InsufficientAvailability::class);
        $this->manualOrder($this->manager, $b, 2);
    }

    public function test_ordermentum_orders_are_always_accepted_and_flagged_oversold()
    {
        Event::fake([OrderOversold::class]);
        $p = Product::factory()->create();
        $this->stockIn($p, 2, $this->bin('STOCK'));

        $order = $this->channelOrder(OrderChannel::Ordermentum, 'om-uuid-1', 1, [[$p, 5]]);

        $this->assertTrue($order->oversold);
        $this->assertSame(-3, $this->available($p));
        Event::assertDispatched(OrderOversold::class, fn ($e) => $e->orderId === $order->id && $e->skus === [$p->sku]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'order_oversold', 'auditable_id' => $order->id]);
    }

    public function test_manual_backorder_is_allowed_when_explicitly_requested()
    {
        $p = Product::factory()->create();
        $order = $this->manualOrder($this->manager, $p, 3, allowBackorder: true);

        $this->assertTrue($order->oversold);
        $this->assertSame(-3, $this->available($p));
    }

    public function test_channel_upsert_is_idempotent_and_ignores_stale_versions()
    {
        $p = Product::factory()->create();
        $this->stockIn($p, 20, $this->bin('STOCK'));

        $v1 = $this->channelOrder(OrderChannel::Portal, 'P-1', 1, [[$p, 5, 'L1']]);
        $again = $this->channelOrder(OrderChannel::Portal, 'P-1', 1, [[$p, 5, 'L1']]);
        $this->assertTrue($v1->is($again));
        $this->assertSame(1, Order::count());
        $this->assertSame(15, $this->available($p));

        $this->channelOrder(OrderChannel::Portal, 'P-1', 3, [[$p, 2, 'L1']]); // reduce
        $this->assertSame(18, $this->available($p));

        $this->channelOrder(OrderChannel::Portal, 'P-1', 2, [[$p, 9, 'L1']]); // stale: ignored
        $this->assertSame(18, $this->available($p));
    }

    public function test_channel_cancel_releases_reservation()
    {
        $p = Product::factory()->create();
        $this->stockIn($p, 10, $this->bin('STOCK'));
        $this->channelOrder(OrderChannel::Portal, 'P-2', 1, [[$p, 6]]);

        $order = $this->channelOrder(OrderChannel::Portal, 'P-2', 2, [[$p, 6]], 'cancelled');

        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertSame(10, $this->available($p));
    }

    public function test_removed_line_releases_its_reservation()
    {
        $a = Product::factory()->create();
        $b = Product::factory()->create();
        $this->stockIn($a, 10, $this->bin('STOCK'));
        $this->stockIn($b, 10, $this->bin('STOCK'));
        $this->channelOrder(OrderChannel::Portal, 'P-3', 1, [[$a, 2, 'L1'], [$b, 3, 'L2']]);

        $this->channelOrder(OrderChannel::Portal, 'P-3', 2, [[$a, 2, 'L1']]);

        $this->assertSame(10, $this->available($b));
        $this->assertSame(8, $this->available($a));
    }

    public function test_availability_is_per_site()
    {
        $p = Product::factory()->create();
        $site2 = Location::create(['type' => 'site', 'code' => 'SITE2', 'name' => 'Second', 'is_sellable' => false]);
        DefaultSiteSeeder::seedStandardBins($site2);
        $this->stockIn($p, 3, $this->bin('STOCK'));
        $this->stockIn($p, 10, $this->bin('STOCK', $site2));

        $this->expectException(InsufficientAvailability::class);
        $this->manualOrder($this->manager, $p, 5); // Clontarf only has 3
    }

    public function test_expired_and_quarantined_stock_is_not_available()
    {
        $p = Product::factory()->batchTracked()->create();
        $this->stockIn($p, 5, $this->bin('STOCK'), $this->batch($p, 'OLD', '2020-01-01'));
        $this->stockIn($p, 5, $this->bin('QUARANTINE'), $this->batch($p, 'Q', '2099-01-01'));

        $this->assertSame(0, $this->available($p));
    }

    public function test_reservation_changes_fire_stock_changed_for_channel_sync()
    {
        Event::fake([StockChanged::class]);
        $p = Product::factory()->create();
        $this->stockIn($p, 5, $this->bin('STOCK'));
        Event::fake([StockChanged::class]);

        $this->manualOrder($this->manager, $p, 1);

        Event::assertDispatched(StockChanged::class, fn ($e) => $e->productIds === [$p->id]);
    }

    public function test_manual_order_cancel_via_http()
    {
        $p = Product::factory()->create();
        $this->stockIn($p, 5, $this->bin('STOCK'));
        $order = $this->manualOrder($this->manager, $p, 5);

        $this->actingAs($this->manager)->post(route('orders.cancel', $order), ['reason' => 'Customer called'])->assertSessionHasNoErrors();

        $this->assertSame(5, $this->available($p));
        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
    }

    public function test_unknown_channel_order_cannot_be_cancelled()
    {
        $this->expectException(InvalidMovement::class);
        $this->channelOrder(OrderChannel::Portal, 'never-seen', 1, [[Product::factory()->create(), 1]], 'cancelled');
    }

    public function test_service_is_resolvable_for_http_create()
    {
        $p = Product::factory()->create();
        $this->stockIn($p, 5, $this->bin('STOCK'));
        $customer = Customer::factory()->create();

        $this->actingAs($this->manager)->post(route('orders.store'), [
            'customer_id' => $customer->id, 'site_id' => $this->site()->id, 'short_ship_policy' => 'backorder',
            'lines' => [['product_id' => $p->id, 'product_unit_id' => $p->units()->value('id'), 'qty' => 9]],
        ])->assertSessionHasErrors('order');

        $this->assertSame(0, Order::count());
        $this->assertInstanceOf(OrderService::class, app(OrderService::class));
    }
}
