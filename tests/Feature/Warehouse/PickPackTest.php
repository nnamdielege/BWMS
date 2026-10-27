<?php

namespace Tests\Feature\Warehouse;

use App\Authorization\Permissions;
use App\Enums\OrderStatus;
use App\Models\CountTask;
use App\Models\Customer;
use App\Models\DeliveryArea;
use App\Models\DeliveryRun;
use App\Models\Location;
use App\Models\Order;
use App\Models\PickList;
use App\Models\Product;
use App\Models\User;
use App\Services\Orders\OrderService;
use Database\Seeders\DeliveryAreaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;
use Tests\Support\OrderFixtures;
use Tests\Support\StockFixtures;
use Tests\TestCase;

class PickPackTest extends TestCase
{
    use OrderFixtures, RefreshDatabase, StockFixtures;

    private User $picker;

    private User $manager;

    private Product $ambient;

    private Product $chilled;

    private Location $binA;

    private Location $binZ;

    private Location $fridge;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-02 09:00', 'Australia/Brisbane')); // Monday
        $this->seedStockReferenceData();
        $this->seed(DeliveryAreaSeeder::class);
        $this->picker = $this->userWithRole(Permissions::ROLE_WAREHOUSE_STAFF);
        $this->manager = $this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER);

        $site = $this->site();
        $this->binZ = Location::create(['type' => 'bin', 'parent_id' => $site->id, 'code' => 'Z-9', 'name' => 'Z', 'is_sellable' => true, 'walk_seq' => 90]);
        $this->binA = Location::create(['type' => 'bin', 'parent_id' => $site->id, 'code' => 'A-1', 'name' => 'A', 'is_sellable' => true, 'walk_seq' => 10]);
        $this->fridge = Location::create(['type' => 'bin', 'parent_id' => $site->id, 'code' => 'C-1', 'name' => 'Cool', 'is_sellable' => true, 'walk_seq' => 1]);

        $this->ambient = Product::factory()->withPack('Carton', 6)->create(['sku' => 'TEA-1', 'name' => 'Tea']);
        $this->ambient->units()->where('base_qty', 6)->first()->barcodes()->create(['barcode' => '9300000000019', 'type' => 'ean13']);
        $this->chilled = Product::factory()->create(['sku' => 'MILK-1', 'name' => 'Milk', 'is_chilled' => true]);
        $this->chilled->units()->first()->barcodes()->create(['barcode' => '9300000000026', 'type' => 'ean13']);
        $other = Product::factory()->create(['sku' => 'JAM-1']);
        $this->stockIn($this->ambient, 12, $this->binZ);
        $this->stockIn($this->chilled, 5, $this->fridge);
        $this->stockIn($other, 5, $this->binA);

        // Zone 1 customer: next-day run on Tuesday.
        $customer = Customer::factory()->create(['delivery_postcode' => '4019', 'delivery_suburb' => 'Clontarf']);
        $this->order = app(OrderService::class)->createManual($this->manager, [
            'customer_id' => $customer->id, 'site_id' => $site->id, 'short_ship_policy' => 'backorder',
            'lines' => [
                ['product_id' => $this->chilled->id, 'product_unit_id' => $this->chilled->units()->value('id'), 'qty' => 2],
                ['product_id' => $this->ambient->id, 'product_unit_id' => $this->ambient->units()->where('base_qty', 6)->value('id'), 'qty' => 2],
                ['product_id' => $other->id, 'product_unit_id' => $other->units()->value('id'), 'qty' => 1],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function run_(): DeliveryRun
    {
        return $this->order->fresh()->deliveryRun;
    }

    private function start(): PickList
    {
        $this->actingAs($this->picker)->post(route('pick.start', $this->run_()))->assertRedirect();

        return PickList::latest('id')->first();
    }

    private function record(PickList $list, int $lineId, array $data)
    {
        return $this->actingAs($this->picker)->put(route('pick.record', [$list, $lineId]), $data);
    }

    public function test_bins_get_label_barcodes_and_the_queue_shows_the_run()
    {
        $this->assertSame(Location::barcodeFor($this->binA->id), $this->binA->fresh()->barcode);
        $this->assertSame('2026-11-03', $this->run_()->run_date->toDateString());

        $this->actingAs($this->picker)->get(route('pick.queue'))->assertInertia(fn (AssertableInertia $p) => $p
            ->component('warehouse/PickQueue')->where('runs.0.area', 'Zone 1 · Peninsula')->where('runs.0.waiting', 1));
    }

    public function test_walk_order_is_ambient_by_walk_seq_then_chilled_last()
    {
        $list = $this->start();
        $this->assertSame($this->picker->id, $list->claimed_by);
        $this->assertSame($this->run_()->id, $list->delivery_run_id);

        $this->actingAs($this->picker)->get(route('pick.work', $list))->assertInertia(fn (AssertableInertia $p) => $p
            ->component('warehouse/PickWork')
            ->where('lines.0.bin.code', 'A-1')
            ->where('lines.1.bin.code', 'Z-9')->where('lines.1.to_pick_label', '2 Carton')
            ->where('lines.2.bin.code', 'C-1')->where('lines.2.product.chilled', true));
    }

    public function test_scans_are_verified_and_an_unscanned_pick_needs_a_reason()
    {
        $list = $this->start();
        $tea = $list->lines()->where('product_id', $this->ambient->id)->first();
        $carton = '9300000000019';

        $this->record($list, $tea->id, ['qty_picked_base' => 12, 'bin_barcode' => $this->binA->barcode, 'product_barcodes' => [$carton, $carton]])
            ->assertSessionHasErrors(['pick' => 'Wrong bin: scanned '.$this->binA->barcode.', expected Z-9.']);
        $this->record($list, $tea->id, ['qty_picked_base' => 12, 'bin_barcode' => $this->binZ->barcode, 'product_barcodes' => ['9300000000026']])
            ->assertSessionHasErrors('pick');
        $this->record($list, $tea->id, ['qty_picked_base' => 12])->assertSessionHasErrors(['pick' => 'Scan the bin and the product, or choose why not.']);

        $this->record($list, $tea->id, ['qty_picked_base' => 12, 'bin_barcode' => $this->binZ->barcode, 'product_barcodes' => [$carton, $carton]])->assertSessionHasNoErrors();
        $tea->refresh();
        $this->assertSame([12, null], [$tea->qty_picked_base, $tea->override_reason]);
        $this->assertNotNull($tea->bin_scanned_at);

        $milk = $list->lines()->where('product_id', $this->chilled->id)->first();
        $this->record($list, $milk->id, ['qty_picked_base' => 2, 'override_reason' => 'label_damaged'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('audit_logs', ['event' => 'pick_scan_override']);
    }

    public function test_short_pick_raises_a_count_task_and_another_picker_must_take_over()
    {
        $list = $this->start();
        $jam = $list->lines()->where('product_id', '!=', $this->ambient->id)->where('product_id', '!=', $this->chilled->id)->first();

        $this->record($list, $jam->id, ['qty_picked_base' => 0])->assertSessionHasNoErrors();
        $task = CountTask::sole();
        $this->assertSame([CountTask::REASON_SHORT_PICK, $this->binA->id], [$task->reason, $task->location_id]);

        $other = $this->userWithRole(Permissions::ROLE_WAREHOUSE_STAFF);
        $this->actingAs($other)->put(route('pick.record', [$list, $jam->id]), ['qty_picked_base' => 0])->assertSessionHasErrors('pick');
        $this->actingAs($other)->post(route('pick.claim', $list), ['take_over' => true])->assertRedirect(route('pick.work', $list));
        $this->assertSame($other->id, $list->fresh()->claimed_by);
        $this->assertDatabaseHas('audit_logs', ['event' => 'pick_list_taken_over']);
    }

    public function test_pack_needs_every_line_ticked_then_the_run_dispatches()
    {
        $list = $this->start();
        foreach ($list->lines as $line) {
            $this->record($list, $line->id, ['qty_picked_base' => $line->qty_to_pick_base, 'override_reason' => 'scanner_fault'])->assertSessionHasNoErrors();
        }

        $this->actingAs($this->picker)->post(route('pick.dispatch', $this->run_()))->assertSessionHasErrors(['pick' => "Pack every order on {$list->number} before dispatch."]);

        $ids = $list->lines()->pluck('id')->all();
        $this->actingAs($this->picker)->post(route('pick.pack.store', [$list, $this->order]), ['cartons' => 2, 'checked' => array_slice($ids, 1)])
            ->assertSessionHasErrors(['pick' => 'Tick every line as packed.']);
        $this->actingAs($this->picker)->post(route('pick.pack.store', [$list, $this->order]), ['cartons' => 2, 'checked' => $ids])->assertSessionHasNoErrors();

        $slip = $this->actingAs($this->picker)->get(route('pick.slip', [$list, $this->order]));
        $slip->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $slip->getContent());

        $this->actingAs($this->picker)->post(route('pick.dispatch', $this->run_()))->assertSessionHasNoErrors();
        $this->assertSame(OrderStatus::Dispatched, $this->order->fresh()->status);
        $this->assertSame(DeliveryRun::STATUS_DISPATCHED, $this->run_()->status);
        $this->actingAs($this->picker)->get(route('pick.manifest', $this->run_()))->assertInertia(fn (AssertableInertia $p) => $p
            ->component('warehouse/RunManifest')->where('orders.0.cartons', 2)->where('orders.0.packed', true));
    }

    public function test_bin_labels_pdf_and_templates_stay_offline()
    {
        $res = $this->actingAs($this->picker)->get(route('locations.labels', ['site' => $this->site()->id]));
        $res->assertOk()->assertHeader('Content-Type', 'application/pdf');

        foreach (['packing-slip', 'bin-labels'] as $tpl) {
            $this->assertDoesNotMatchRegularExpression('#(https?:)?//|@import#i', File::get(resource_path("views/pdf/{$tpl}.blade.php")), "{$tpl} must not reference remote resources (NFR-6)");
        }
    }

    public function test_delivery_areas_are_editable_and_a_place_belongs_to_one_area()
    {
        $admin = $this->userWithRole(Permissions::ROLE_ADMIN);
        $gold = DeliveryArea::where('code', 'GOLDCOAST')->sole();

        $this->actingAs($admin)->get(route('admin.delivery-areas.index'))->assertInertia(fn (AssertableInertia $p) => $p->component('admin/DeliveryAreas')->has('areas', 8));
        $this->actingAs($admin)->put(route('admin.delivery-areas.update', $gold), [
            'code' => 'GOLDCOAST', 'name' => 'Gold Coast', 'weekdays' => [2], 'next_day_eligible' => false, 'is_active' => true, 'places' => "4217\n4019",
        ])->assertSessionHasErrors(['places' => '4019 is already in Zone 1 · Peninsula.']);
        $this->actingAs($admin)->put(route('admin.delivery-areas.update', $gold), [
            'code' => 'GOLDCOAST', 'name' => 'Gold Coast', 'weekdays' => [2, 4], 'next_day_eligible' => false, 'is_active' => true, 'places' => "4217\n4218 Broadbeach",
        ])->assertSessionHasNoErrors();
        $this->assertSame(['4217', '4218'], $gold->places()->pluck('postcode')->all());
        $this->assertSame([2, 4], $gold->fresh()->weekdays);

        $this->actingAs($this->picker)->get(route('admin.delivery-areas.index'))->assertForbidden();
    }
}
