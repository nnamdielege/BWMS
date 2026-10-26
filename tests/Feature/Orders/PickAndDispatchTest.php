<?php

namespace Tests\Feature\Orders;

use App\Authorization\Permissions;
use App\Enums\MovementType;
use App\Enums\OrderStatus;
use App\Events\OrderDispatched;
use App\Models\Location;
use App\Models\PickList;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Orders\AvailabilityService;
use App\Services\Orders\OrderService;
use App\Services\Orders\PickListService;
use App\Services\Stock\Exceptions\InvalidMovement;
use Database\Seeders\DefaultSiteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Support\OrderFixtures;
use Tests\Support\StockFixtures;
use Tests\TestCase;

class PickAndDispatchTest extends TestCase
{
    use OrderFixtures, RefreshDatabase, StockFixtures;

    private User $manager;

    private User $picker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedStockReferenceData();
        $this->manager = $this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER);
        $this->picker = $this->userWithRole(Permissions::ROLE_WAREHOUSE_STAFF);
    }

    private function picks(): PickListService
    {
        return app(PickListService::class);
    }

    public function test_pick_list_uses_fefo_within_site()
    {
        $p = Product::factory()->batchTracked()->create();
        $shelf = Location::factory()->create(['parent_id' => $this->site()->id, 'code' => 'SHELF-1']);
        $late = $this->batch($p, 'LATE', '2099-06-01');
        $early = $this->batch($p, 'EARLY', '2099-01-01');
        $this->stockIn($p, 10, $this->bin('STOCK'), $late);
        $this->stockIn($p, 3, $shelf, $early);
        $order = $this->manualOrder($this->manager, $p, 5);

        ['pickList' => $list, 'shortfalls' => $short] = $this->picks()->create($this->picker, $this->site(), [$order->id]);

        $this->assertSame([], $short);
        $this->assertSame([['EARLY', 3], ['LATE', 2]], $list->lines()->with('batch')->orderBy('id')->get()->map(fn ($l) => [$l->batch->lot_code, $l->qty_to_pick_base])->all());
        $this->assertSame(OrderStatus::Picking, $order->fresh()->status);
    }

    public function test_second_pick_list_does_not_reuse_units_promised_to_the_first()
    {
        $p = Product::factory()->create();
        $other = Location::factory()->create(['parent_id' => $this->site()->id, 'code' => 'SHELF-2', 'sort_order' => 5]);
        $this->stockIn($p, 4, $this->bin('STOCK'));
        $this->stockIn($p, 4, $other);
        $o1 = $this->manualOrder($this->manager, $p, 4);
        $o2 = $this->manualOrder($this->manager, $p, 4);

        $first = $this->picks()->create($this->picker, $this->site(), [$o1->id])['pickList'];
        $second = $this->picks()->create($this->picker, $this->site(), [$o2->id])['pickList'];

        $this->assertNotSame($first->lines()->value('location_id'), $second->lines()->value('location_id'));
    }

    public function test_dispatch_posts_sales_consumes_reservations_and_is_idempotent()
    {
        Event::fake([OrderDispatched::class]);
        $p = Product::factory()->create();
        $this->stockIn($p, 10, $this->bin('STOCK'));
        $order = $this->manualOrder($this->manager, $p, 6);
        $list = $this->picks()->create($this->picker, $this->site(), [$order->id])['pickList'];

        $this->actingAs($this->picker)->post(route('pick-lists.dispatch', $list))->assertSessionHasErrors('pick'); // nothing confirmed yet

        $this->actingAs($this->picker)->put(route('pick-lists.record', $list), ['picked' => [$list->lines()->value('id') => 6]])->assertSessionHasNoErrors();
        $this->actingAs($this->picker)->post(route('pick-lists.dispatch', $list))->assertSessionHasNoErrors();
        $this->picks()->dispatch($list->fresh(), $this->picker); // double submit

        $this->assertSame(1, StockMovement::where('type', MovementType::Sale)->count());
        $this->assertSame(4, $this->balance($p, $this->bin('STOCK')));
        $this->assertSame(OrderStatus::Dispatched, $order->fresh()->status);
        $this->assertSame(0, $order->lines->first()->reservation->fresh()->qty_base);
        $this->assertSame(4, app(AvailabilityService::class)->available($p));
        $this->assertSame(PickList::STATUS_DISPATCHED, $list->fresh()->status);
        Event::assertDispatched(OrderDispatched::class);
    }

    public function test_short_pick_backorders_by_default()
    {
        $p = Product::factory()->create();
        $this->stockIn($p, 10, $this->bin('STOCK'));
        $order = $this->manualOrder($this->manager, $p, 6);
        $list = $this->picks()->create($this->picker, $this->site(), [$order->id])['pickList'];

        $this->picks()->recordPicked($list, [$list->lines()->value('id') => 4]);
        $this->picks()->dispatch($list, $this->picker);

        $order->refresh();
        $this->assertSame(OrderStatus::PartiallyDispatched, $order->status);
        $this->assertSame(2, $order->lines->first()->reservation->qty_base, 'Remaining 2 stay reserved as a backorder.');
        $this->assertSame(4, app(AvailabilityService::class)->available($p)); // 6 on hand - 2 reserved

        // The backorder can be picked again.
        $next = $this->picks()->create($this->picker, $this->site(), [$order->id])['pickList'];
        $this->assertSame(2, (int) $next->lines()->sum('qty_to_pick_base'));
    }

    public function test_short_pick_with_cancel_policy_releases_the_rest()
    {
        $p = Product::factory()->create();
        $this->stockIn($p, 10, $this->bin('STOCK'));
        $order = $this->manualOrder($this->manager, $p, 6, policy: 'cancel');
        $list = $this->picks()->create($this->picker, $this->site(), [$order->id])['pickList'];

        $this->picks()->recordPicked($list, [$list->lines()->value('id') => 4]);
        $this->picks()->dispatch($list, $this->picker);

        $order->refresh();
        $this->assertSame(OrderStatus::Dispatched, $order->status);
        $this->assertSame(2, $order->lines->first()->qty_cancelled_base);
        $this->assertSame(6, app(AvailabilityService::class)->available($p));
    }

    public function test_cannot_over_pick()
    {
        $p = Product::factory()->create();
        $this->stockIn($p, 10, $this->bin('STOCK'));
        $order = $this->manualOrder($this->manager, $p, 2);
        $list = $this->picks()->create($this->picker, $this->site(), [$order->id])['pickList'];

        $this->expectException(InvalidMovement::class);
        $this->picks()->recordPicked($list, [$list->lines()->value('id') => 3]);
    }

    public function test_cancelling_an_order_on_an_open_pick_list_removes_its_lines_and_moves_no_stock()
    {
        $p = Product::factory()->create();
        $this->stockIn($p, 10, $this->bin('STOCK'));
        $order = $this->manualOrder($this->manager, $p, 3);
        $list = $this->picks()->create($this->picker, $this->site(), [$order->id])['pickList'];

        app(OrderService::class)->cancel($order, $this->manager, 'Changed mind');

        $this->assertSame(0, $list->lines()->count());
        $this->assertSame(10, $this->balance($p, $this->bin('STOCK')));
        $this->assertSame(10, app(AvailabilityService::class)->available($p));
    }

    public function test_cancelling_a_pick_list_returns_orders_to_open()
    {
        $p = Product::factory()->create();
        $this->stockIn($p, 10, $this->bin('STOCK'));
        $order = $this->manualOrder($this->manager, $p, 3);
        $list = $this->picks()->create($this->picker, $this->site(), [$order->id])['pickList'];

        $this->actingAs($this->picker)->post(route('pick-lists.cancel', $list))->assertSessionHasNoErrors();

        $this->assertSame(OrderStatus::Open, $order->fresh()->status);
        $this->assertSame(3, $order->lines->first()->reservation->fresh()->qty_base);
    }

    public function test_orders_from_another_site_cannot_be_on_this_pick_list()
    {
        $p = Product::factory()->create();
        $site2 = Location::create(['type' => 'site', 'code' => 'SITE2', 'name' => 'Second', 'is_sellable' => false]);
        DefaultSiteSeeder::seedStandardBins($site2);
        $this->stockIn($p, 5, $this->bin('STOCK', $site2));
        $order = $this->manualOrder($this->manager, $p, 1, siteId: $site2->id);

        $this->expectException(InvalidMovement::class);
        $this->picks()->create($this->picker, $this->site(), [$order->id]);
    }

    public function test_read_only_cannot_pick_and_pages_render()
    {
        $p = Product::factory()->create();
        $this->stockIn($p, 5, $this->bin('STOCK'));
        $order = $this->manualOrder($this->manager, $p, 1);
        $list = $this->picks()->create($this->picker, $this->site(), [$order->id])['pickList'];

        $this->actingAs($this->userWithRole(Permissions::ROLE_READ_ONLY))->get(route('pick-lists.show', $list))->assertForbidden();
        foreach ([route('orders.index'), route('orders.create'), route('orders.show', $order), route('pick-lists.index'), route('pick-lists.show', $list),
            route('customers.index'), route('customers.create'), '/dashboard'] as $url) {
            $this->actingAs($this->manager)->get($url)->assertOk();
        }
    }
}
