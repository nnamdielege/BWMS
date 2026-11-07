<?php

namespace Tests\Feature\Warehouse;

use App\Authorization\Permissions;
use App\Models\Customer;
use App\Models\DeliveryArea;
use App\Models\DeliveryRun;
use App\Models\Order;
use App\Models\PickList;
use App\Models\Product;
use App\Models\User;
use App\Services\Orders\OrderService;
use App\Services\Orders\PickListService;
use App\Services\Orders\PickPackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\Support\StockFixtures;
use Tests\TestCase;

/** A pick list must read the same on Pick & pack and on Pick lists, whichever screen did the work. */
class PickListStatusTest extends TestCase
{
    use RefreshDatabase, StockFixtures;

    private User $manager;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-09 09:00', 'Australia/Brisbane')); // Monday
        $this->seedStockReferenceData();
        $this->manager = $this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER);
        $this->product = Product::factory()->create();
        $this->stockIn($this->product, 100);
        DeliveryArea::create(['code' => 'N', 'name' => 'North', 'weekdays' => [1, 2, 3, 4, 5], 'is_active' => true, 'sort_order' => 1])->places()->create(['postcode' => '4500', 'suburb' => '']);
        DeliveryArea::create(['code' => 'S', 'name' => 'South', 'weekdays' => [1, 2, 3, 4, 5], 'is_active' => true, 'sort_order' => 2])->places()->create(['postcode' => '4600', 'suburb' => '']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function order(string $postcode = '4500'): Order
    {
        return app(OrderService::class)->createManual($this->manager, [
            'customer_id' => Customer::factory()->create(['delivery_postcode' => $postcode])->id, 'site_id' => $this->site()->id, 'short_ship_policy' => 'backorder',
            'requested_delivery_date' => '2026-11-10',
            'lines' => [['product_id' => $this->product->id, 'product_unit_id' => $this->product->units()->value('id'), 'qty' => 1]],
        ]);
    }

    private function onQueue(PickList $list): ?array
    {
        $found = null;
        $this->actingAs($this->manager)->get(route('pick.queue'))->assertInertia(function (AssertableInertia $p) use ($list, &$found) {
            $found = collect($p->toArray()['props']['runs'])->flatMap(fn ($r) => $r['lists'])->firstWhere('id', $list->id);
        });

        return $found;
    }

    private function onDesktop(PickList $list, array $query = []): ?array
    {
        $found = null;
        $this->actingAs($this->manager)->get(route('pick-lists.index', $query))->assertInertia(function (AssertableInertia $p) use ($list, &$found) {
            $props = $p->toArray()['props'];
            $found = collect([...$props['open'], ...($props['history']['data'] ?? [])])->firstWhere('id', $list->id);
        });

        return $found;
    }

    private function pickAll(PickList $list): void
    {
        foreach ($list->lines as $line) {
            app(PickPackService::class)->recordLine($line, $this->manager, $line->qty_to_pick_base, null, [], 'scanner_fault');
        }
    }

    private function packAll(PickList $list, Order $order): void
    {
        app(PickPackService::class)->saveProgress($list, $this->manager, $list->lines()->pluck('id')->all(), []);
        app(PickPackService::class)->pack($list, $order, $this->manager, 1);
    }

    private function assertBothRead(PickList $list, string $stage): void
    {
        $this->assertSame($stage, $this->onDesktop($list)['stage']['key'] ?? null, 'Pick lists');
        $this->assertSame($stage, $this->onQueue($list)['stage']['key'] ?? null, 'Pick & pack');
    }

    public function test_a_handheld_list_reads_the_same_on_both_screens_at_every_stage()
    {
        $order = $this->order();
        $list = app(PickPackService::class)->startNew($order->deliveryRun, $this->manager);
        $this->assertBothRead($list, 'not_started');

        $this->pickAll($list);
        $this->assertBothRead($list->fresh(), 'picked');

        $this->packAll($list, $order);
        $this->assertBothRead($list->fresh(), 'packed');

        app(PickPackService::class)->dispatchRun($order->fresh()->deliveryRun, $this->manager);
        $this->assertSame('dispatched', $this->onDesktop($list->fresh(), ['view' => 'history'])['stage']['key']);
        $this->assertNull($this->onQueue($list), 'Done lists leave the queue');
    }

    public function test_a_partly_picked_list_shows_how_far_it_has_got()
    {
        $order = $this->order();
        $this->order();
        $list = app(PickPackService::class)->startNew($order->deliveryRun, $this->manager);
        $line = $list->lines()->first();
        app(PickPackService::class)->recordLine($line, $this->manager, 1, null, [], 'scanner_fault');

        $this->assertBothRead($list, 'picking');
        $this->assertSame('Picking 1/2', $this->onQueue($list)['stage']['label']);
    }

    public function test_a_list_made_on_the_desktop_shows_on_pick_and_pack()
    {
        $order = $this->order();

        $list = app(PickListService::class)->create($this->manager, $this->site(), [$order->id])['pickList'];

        $this->assertNotNull($this->onQueue($list), 'It would otherwise sit as picking with no list to open');
        $this->assertSame($order->delivery_run_id, $list->fresh()->delivery_run_id);
    }

    public function test_a_desktop_list_across_two_runs_shows_under_both()
    {
        $north = $this->order('4500');
        $south = $this->order('4600');

        $list = app(PickListService::class)->create($this->manager, $this->site(), [$north->id, $south->id])['pickList'];

        $this->actingAs($this->manager)->get(route('pick.queue'))->assertInertia(fn (AssertableInertia $p) => $p
            ->where('runs', fn ($runs) => collect($runs)->every(fn ($r) => collect($r['lists'])->pluck('id')->contains($list->id))));
    }

    public function test_dispatching_on_the_desktop_closes_the_run_on_pick_and_pack()
    {
        $order = $this->order();
        $list = app(PickListService::class)->create($this->manager, $this->site(), [$order->id])['pickList'];
        $this->pickAll($list);

        $this->actingAs($this->manager)->post(route('pick-lists.dispatch', $list))->assertSessionHasNoErrors();

        $this->assertSame(DeliveryRun::STATUS_DISPATCHED, $order->fresh()->deliveryRun->status);
        $this->assertNull($this->onQueue($list));
    }

    public function test_a_run_with_orders_still_to_go_stays_open_after_a_desktop_dispatch()
    {
        $picked = $this->order();
        $this->order();
        $list = app(PickListService::class)->create($this->manager, $this->site(), [$picked->id])['pickList'];
        $this->pickAll($list);

        app(PickListService::class)->dispatch($list, $this->manager);

        $this->assertSame(DeliveryRun::STATUS_OPEN, $picked->fresh()->deliveryRun->status);
    }

    public function test_cancelling_reads_the_same_everywhere()
    {
        $order = $this->order();
        $list = app(PickPackService::class)->startNew($order->deliveryRun, $this->manager);

        app(PickListService::class)->cancel($list);

        $this->assertNull($this->onQueue($list));
        $this->assertNull($this->onDesktop($list), 'Not with the open lists');
        $this->assertSame('cancelled', $this->onDesktop($list, ['view' => 'history'])['stage']['key']);
    }

    public function test_pick_lists_shows_open_lists_by_default_and_the_rest_under_history()
    {
        $open = app(PickPackService::class)->startNew($this->order('4500')->deliveryRun, $this->manager);
        $done = app(PickPackService::class)->startNew($this->order('4600')->deliveryRun, $this->manager);
        app(PickListService::class)->cancel($done);

        $this->actingAs($this->manager)->get(route('pick-lists.index'))->assertInertia(fn (AssertableInertia $p) => $p
            ->where('view', 'open')->where('open.0.id', $open->id)->has('open', 1)->where('history', null));
        $this->actingAs($this->manager)->get(route('pick-lists.index', ['view' => 'history']))->assertInertia(fn (AssertableInertia $p) => $p
            ->where('view', 'history')->where('history.data.0.id', $done->id)->has('history.data', 1)->where('open', []));
    }

    public function test_the_desktop_page_for_one_list_reads_the_same()
    {
        $order = $this->order();
        $list = app(PickPackService::class)->startNew($order->deliveryRun, $this->manager);
        $this->pickAll($list);

        $this->actingAs($this->manager)->get(route('pick-lists.show', $list))->assertInertia(fn (AssertableInertia $p) => $p->where('pickList.stage.key', 'picked'));
    }
}
