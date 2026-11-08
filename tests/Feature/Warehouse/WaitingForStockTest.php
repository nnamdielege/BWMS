<?php

namespace Tests\Feature\Warehouse;

use App\Authorization\Permissions;
use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\DeliveryArea;
use App\Models\DeliveryRun;
use App\Models\Order;
use App\Models\PickList;
use App\Models\PickListLine;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Orders\OrderService;
use App\Services\Orders\PickListService;
use App\Services\Orders\PickPackService;
use App\Services\Stock\Exceptions\InvalidMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\Support\OrderFixtures;
use Tests\Support\StockFixtures;
use Tests\TestCase;

/** Lines with no stock must not look like a new order to pick. As SO-2026-00010: one line picked, MLKALM never allocated. */
class WaitingForStockTest extends TestCase
{
    use OrderFixtures, RefreshDatabase, StockFixtures;

    private User $manager;

    private Product $tea;

    private Product $milk;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-09 09:00', 'Australia/Brisbane')); // Monday
        $this->seedStockReferenceData();
        $this->manager = $this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER);
        $this->tea = Product::factory()->create(['sku' => 'TEA-1', 'name' => 'Tea']);
        $this->milk = Product::factory()->create(['sku' => 'MLKALM', 'name' => 'Almond milk']);
        $this->stockIn($this->tea, 50);
        DeliveryArea::create(['code' => 'N', 'name' => 'North', 'weekdays' => [1, 2, 3, 4, 5], 'is_active' => true, 'sort_order' => 1])->places()->create(['postcode' => '4500', 'suburb' => '']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Manual orders can be taken beyond stock, like Ordermentum's. */
    private function order(array $products, string $policy = 'backorder'): Order
    {
        return app(OrderService::class)->createManual($this->manager, [
            'customer_id' => Customer::factory()->create(['delivery_postcode' => '4500'])->id, 'site_id' => $this->site()->id, 'short_ship_policy' => $policy,
            'requested_delivery_date' => '2026-11-10',
            'lines' => array_map(fn (Product $p) => ['product_id' => $p->id, 'product_unit_id' => $p->units()->value('id'), 'qty' => 2], $products),
        ], allowBackorder: true);
    }

    private function start(Order $order): PickList
    {
        return app(PickPackService::class)->startNew($order->fresh()->deliveryRun, $this->manager);
    }

    private function noStockLine(PickList $list): PickListLine
    {
        return $list->lines()->where('no_stock', true)->sole();
    }

    /** Picks what can be picked and confirms the rest short. */
    private function finish(PickList $list): void
    {
        foreach ($list->lines as $line) {
            app(PickPackService::class)->recordLine($line, $this->manager, $line->no_stock ? 0 : $line->qty_to_pick_base, null, [], $line->no_stock ? null : 'scanner_fault');
        }
    }

    /** The order from the report, after its first dispatch. */
    private function partlyDispatched(): Order
    {
        $order = $this->order([$this->tea, $this->milk]);
        $list = $this->start($order);
        $this->finish($list);
        app(PickListService::class)->dispatch($list, $this->manager);

        return $order->fresh();
    }

    private function queueRun(Order $order): array
    {
        $found = null;
        $this->actingAs($this->manager)->get(route('pick.queue'))->assertInertia(function (AssertableInertia $p) use ($order, &$found) {
            $found = collect($p->toArray()['props']['runs'])->firstWhere('id', $order->delivery_run_id);
        });

        return $found;
    }

    public function test_a_line_with_no_stock_goes_on_the_list_as_no_stock()
    {
        $list = $this->start($this->order([$this->tea, $this->milk]));

        $line = $this->noStockLine($list);
        $this->assertSame($this->milk->id, $line->product_id);
        $this->assertNull($line->location_id);
        $this->assertSame(2, $line->qty_to_pick_base);
        $this->assertSame(2, $list->lines()->count());
    }

    public function test_a_line_with_some_stock_splits_into_a_pick_and_a_no_stock_row()
    {
        $this->stockIn($this->milk, 1);

        $list = $this->start($this->order([$this->milk]));

        $this->assertSame([[false, 1], [true, 1]], $list->lines()->orderBy('id')->get()->map(fn ($l) => [$l->no_stock, $l->qty_to_pick_base])->all());
    }

    public function test_the_list_shows_the_shortfall_to_the_picker_and_desktop()
    {
        $list = $this->start($this->order([$this->tea, $this->milk]));

        $this->actingAs($this->manager)->get(route('pick.work', $list))->assertInertia(fn (AssertableInertia $p) => $p
            ->where('lines.1.no_stock', true)->where('lines.1.bin', null)->where('lines.1.product.sku', 'MLKALM')->where('lines.0.no_stock', false));
        $this->actingAs($this->manager)->get(route('pick-lists.show', $list))->assertInertia(fn (AssertableInertia $p) => $p
            ->where('pickList.lines', fn ($lines) => collect($lines)->firstWhere('no_stock', true)['location'] === 'No stock — short'));
    }

    public function test_a_no_stock_line_is_confirmed_short_and_never_as_picked()
    {
        $list = $this->start($this->order([$this->tea, $this->milk]));
        $line = $this->noStockLine($list);

        $this->assertThrows(fn () => app(PickPackService::class)->recordLine($line, $this->manager, 1, null, [], 'scanner_fault'), InvalidMovement::class);
        $this->assertThrows(fn () => app(PickListService::class)->recordPicked($list, [$line->id => 1]), InvalidMovement::class);

        app(PickPackService::class)->recordLine($line, $this->manager, 0, null, [], null);

        $this->assertSame(0, $line->fresh()->qty_picked_base);
    }

    public function test_it_must_be_confirmed_before_dispatch()
    {
        $list = $this->start($this->order([$this->tea, $this->milk]));
        $real = $list->lines()->where('no_stock', false)->sole();
        app(PickPackService::class)->recordLine($real, $this->manager, 2, null, [], 'scanner_fault');

        $this->expectExceptionMessage('Confirm the picked quantity');
        app(PickListService::class)->dispatch($list, $this->manager);
    }

    public function test_no_stock_lines_need_no_tick_to_pack_and_move_no_stock()
    {
        $order = $this->order([$this->tea, $this->milk]);
        $list = $this->start($order);
        $this->finish($list);

        app(PickPackService::class)->saveProgress($list, $this->manager, $list->lines()->where('no_stock', false)->pluck('id')->all(), []);
        app(PickPackService::class)->pack($list, $order, $this->manager, 1);
        app(PickPackService::class)->dispatchRun($order->fresh()->deliveryRun, $this->manager);

        $this->assertSame(OrderStatus::PartiallyDispatched, $order->fresh()->status);
        $this->assertSame(48, $this->balance($this->tea));
        $this->assertSame(0, StockMovement::where('product_id', $this->milk->id)->count());
    }

    public function test_the_report_the_rest_shows_as_waiting_for_stock_not_a_new_order()
    {
        $order = $this->partlyDispatched();

        $run = $this->queueRun($order);

        $this->assertSame(0, $run['waiting'], 'Not "Pick 1 new order"');
        $this->assertSame($order->number, $run['waiting_stock'][0]['number']);
        $this->assertSame([['sku' => 'MLKALM', 'name' => 'Almond milk', 'short' => '2 each']], $run['waiting_stock'][0]['lines']);
    }

    public function test_pick_new_orders_counts_only_orders_with_something_to_pick()
    {
        $this->order([$this->milk]);
        $ready = $this->order([$this->tea, $this->milk]);

        $this->assertSame([$ready->id], app(PickPackService::class)->waitingOrders($ready->fresh()->deliveryRun)->pluck('id')->all());
        $this->assertSame(1, $this->queueRun($ready)['waiting']);
    }

    public function test_an_order_with_nothing_allocatable_isnt_pulled_onto_a_list()
    {
        $none = $this->order([$this->milk]);
        $ready = $this->order([$this->tea]);

        $list = $this->start($ready);

        $this->assertSame(1, $list->lines()->count());
        $this->assertSame('open', $none->fresh()->status->value);
    }

    public function test_only_no_stock_orders_leave_nothing_to_start()
    {
        $order = $this->order([$this->milk]);

        $this->expectException(InvalidMovement::class);
        $this->start($order);
    }

    public function test_the_last_of_the_stock_is_only_promised_once()
    {
        $this->stockIn($this->milk, 2);
        $first = $this->order([$this->milk]);
        $second = $this->order([$this->milk]);

        $this->assertSame([$first->id], app(PickPackService::class)->waitingOrders($first->fresh()->deliveryRun)->pluck('id')->all());
        $this->assertSame($second->number, $this->queueRun($first)['waiting_stock'][0]['number']);
    }

    public function test_stock_on_another_open_list_doesnt_count()
    {
        $this->stockIn($this->milk, 2);
        $this->start($this->order([$this->milk]));
        $second = $this->order([$this->milk]);

        $this->assertSame(0, $this->queueRun($second)['waiting']);
    }

    public function test_it_becomes_pickable_by_itself_once_stock_arrives()
    {
        $order = $this->partlyDispatched();
        $this->assertSame(0, $this->queueRun($order)['waiting']);

        $this->stockIn($this->milk, 10);

        $run = $this->queueRun($order);
        $this->assertSame(1, $run['waiting']);
        $this->assertSame([], $run['waiting_stock']);

        $list = $this->start($order);
        $this->assertSame(0, $list->lines()->where('no_stock', true)->count());
        $this->assertSame($this->milk->id, $list->lines()->sole()->product_id);
    }

    public function test_a_manager_cancels_the_rest_and_the_order_closes()
    {
        $order = $this->partlyDispatched();

        $this->actingAs($this->manager)->post(route('orders.cancel', $order), ['reason' => 'No stock'])->assertSessionHas('success');

        $order->refresh();
        $this->assertSame(OrderStatus::Dispatched, $order->status);
        $this->assertSame([], $this->queueRun($order)['waiting_stock'] ?? []);
    }

    public function test_cancelling_the_rest_lets_the_run_close_on_the_next_dispatch()
    {
        $order = $this->order([$this->milk]);
        app(OrderService::class)->cancel($order, $this->manager, 'No stock');

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertSame(DeliveryRun::STATUS_OPEN, $order->fresh()->deliveryRun->status);
    }

    public function test_cancel_rest_is_for_managers()
    {
        $order = $this->partlyDispatched();

        $this->actingAs($this->userWithRole(Permissions::ROLE_WAREHOUSE_STAFF))->post(route('orders.cancel', $order), ['reason' => 'x'])->assertForbidden();
        $this->actingAs($this->userWithRole(Permissions::ROLE_WAREHOUSE_STAFF))->get(route('pick.queue'))->assertInertia(fn (AssertableInertia $p) => $p->where('canCancel', false));
        $this->actingAs($this->manager)->get(route('pick.queue'))->assertInertia(fn (AssertableInertia $p) => $p->where('canCancel', true));
    }

    public function test_the_orders_page_shows_it_per_run()
    {
        $order = $this->partlyDispatched();

        $this->actingAs($this->manager)->get(route('orders.index'))->assertInertia(fn (AssertableInertia $p) => $p
            ->where('waitingForStock.0.run', $order->deliveryRun->label())
            ->where('waitingForStock.0.orders.0.number', $order->number)
            ->where('waitingForStock.0.orders.0.lines.0.sku', 'MLKALM'));
    }

    public function test_a_held_order_is_held_not_waiting_for_stock()
    {
        $order = $this->channelOrder(OrderChannel::Portal, 'p-1', 1, [[$this->tea, 1]], delivery: ['postcode' => '4500'], date: '2026-11-10');
        Order::whereKey($order->id)->update(['payment_hold' => true]);

        $run = $this->queueRun($order);

        $this->assertSame(0, $run['waiting']);
        $this->assertSame([], $run['waiting_stock']);
    }
}
