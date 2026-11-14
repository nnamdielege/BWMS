<?php

namespace Tests\Feature\Warehouse;

use App\Authorization\Permissions;
use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Order;
use App\Models\PickList;
use App\Models\PickListLine;
use App\Models\Product;
use App\Models\User;
use App\Services\Orders\OrderService;
use Database\Seeders\DeliveryAreaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\Support\StockFixtures;
use Tests\TestCase;

/**
 * PK-2026-00030: an open list for Fri 13 Nov's run, with a substitute line added after a short
 * pick, was missing from Pick lists (desktop) once overdue and couldn't be picked.
 */
class OverdueSubstitutePickListTest extends TestCase
{
    use RefreshDatabase, StockFixtures;

    private User $manager;

    private User $picker;

    private Product $tea;

    private Product $coffee;

    private Order $order;

    private PickList $list;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-12 09:00', 'Australia/Brisbane')); // Thursday
        $this->seedStockReferenceData();
        $this->seed(DeliveryAreaSeeder::class);
        $this->manager = $this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER);
        $this->picker = $this->userWithRole(Permissions::ROLE_WAREHOUSE_STAFF);
        $bin = Location::create(['type' => 'bin', 'parent_id' => $this->site()->id, 'code' => 'A-1', 'name' => 'A', 'is_sellable' => true, 'walk_seq' => 10]);
        $this->tea = Product::factory()->create(['sku' => 'TEA', 'name' => 'Tea', 'sell_price' => 5]);
        $this->coffee = Product::factory()->create(['sku' => 'COF', 'name' => 'Coffee', 'sell_price' => 4]);
        $this->stockIn($this->tea, 4, $bin);
        $this->stockIn($this->coffee, 20, $bin);

        // Zone 1: next-day run, Fri 13 Nov.
        $customer = Customer::factory()->create(['delivery_postcode' => '4019', 'delivery_suburb' => 'Clontarf']);
        $this->order = app(OrderService::class)->createManual($this->manager, [
            'customer_id' => $customer->id, 'site_id' => $this->site()->id, 'short_ship_policy' => 'backorder',
            'lines' => [['product_id' => $this->tea->id, 'product_unit_id' => $this->tea->units()->value('id'), 'qty' => 10]],
        ], allowBackorder: true);
        $this->assertSame('2026-11-13', $this->order->fresh()->deliveryRun->run_date->toDateString());

        // Picked on the handheld: 4 found, 6 with no stock; the manager substitutes the 6.
        $this->actingAs($this->picker)->post(route('pick.start', $this->order->fresh()->deliveryRun))->assertRedirect();
        $this->list = PickList::sole();
        $found = $this->list->lines()->where('no_stock', false)->sole();
        $this->actingAs($this->picker)->put(route('pick.record', [$this->list, $found]), ['each' => 4, 'override_reason' => 'label_missing'])->assertSessionHasNoErrors();
        $short = $this->list->lines()->where('no_stock', true)->sole();
        $this->actingAs($this->picker)->put(route('pick.record', [$this->list, $short]), ['each' => 0])->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post(route('orders.substitute', [$this->order, $short->orderLine]), [
            'replace' => ['each' => 6], 'product_id' => $this->coffee->id, 'product_unit_id' => $this->coffee->units()->value('id'),
            'qty' => ['each' => 6], 'note' => 'Accepted by Jane by phone', 'pick_list_line_id' => $short->id,
        ])->assertSessionHasNoErrors();

        Carbon::setTestNow(Carbon::parse('2026-11-14 09:00', 'Australia/Brisbane')); // the run is now overdue
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function subPickLine(): PickListLine
    {
        return $this->list->lines()->where('product_id', $this->coffee->id)->sole();
    }

    public function test_the_overdue_list_shows_on_the_desktop_page_with_its_date()
    {
        $this->actingAs($this->picker)->get(route('pick-lists.index'))->assertInertia(fn (AssertableInertia $p) => $p
            ->has('open', 1)
            ->where('open.0.number', $this->list->number)
            ->where('open.0.delivery_date', '2026-11-13')
            ->where('today', '2026-11-14'));
    }

    public function test_the_overdue_list_shows_in_the_handheld_queue()
    {
        $this->actingAs($this->picker)->get(route('pick.queue'))->assertInertia(fn (AssertableInertia $p) => $p
            ->where('runs', fn ($runs) => collect($runs)->contains(fn ($r) => $r['date'] === '2026-11-13' && collect($r['lists'])->contains('number', $this->list->number))));
    }

    public function test_the_substitute_line_can_be_picked_on_the_handheld_and_the_list_dispatched()
    {
        $line = $this->subPickLine();
        $this->actingAs($this->picker)->get(route('pick.work', $this->list))
            ->assertInertia(fn (AssertableInertia $p) => $p->where('lines', fn ($lines) => collect($lines)->contains(fn ($l) => $l['id'] === $line->id && $l['picked_base'] === null && $l['sub_label'] === 'SUB for TEA Tea')));

        $this->actingAs($this->picker)->put(route('pick.record', [$this->list, $line]), ['each' => 6, 'override_reason' => 'label_missing'])->assertSessionHasNoErrors();
        $this->assertSame(6, $line->fresh()->qty_picked_base);

        $this->actingAs($this->picker)->post(route('pick-lists.dispatch', $this->list))->assertSessionHasNoErrors();
        $this->assertSame(OrderStatus::Dispatched, $this->order->fresh()->status);
    }

    public function test_the_substitute_line_can_be_picked_on_the_desktop_page()
    {
        $line = $this->subPickLine();
        $this->actingAs($this->picker)->get(route('pick-lists.show', $this->list))
            ->assertInertia(fn (AssertableInertia $p) => $p->where('can.pick', true)
                ->where('pickList.lines', fn ($lines) => collect($lines)->contains(fn ($l) => $l['id'] === $line->id && $l['qty_picked_base'] === null)));

        $this->actingAs($this->picker)->put(route('pick-lists.record', $this->list), ['picked' => [$line->id => ['each' => 6]]])->assertSessionHasNoErrors();
        $this->assertSame(6, $line->fresh()->qty_picked_base);
    }
}
