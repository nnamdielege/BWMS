<?php

namespace Tests\Feature\Warehouse;

use App\Authorization\Permissions;
use App\Enums\OrderStatus;
use App\Models\AuditLog;
use App\Models\CountTask;
use App\Models\Customer;
use App\Models\DeliveryArea;
use App\Models\DeliveryRun;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderPack;
use App\Models\OrderPackDraft;
use App\Models\PickList;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Services\Orders\OrderService;
use Database\Seeders\DeliveryAreaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;
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

    /** $data['qty_picked_base'] goes as loose base units; the screen sends packs + each. */
    private function record(PickList $list, int $lineId, array $data)
    {
        if (array_key_exists('qty_picked_base', $data)) {
            $data['each'] = $data['qty_picked_base'];
            unset($data['qty_picked_base']);
        }

        return $this->actingAs($this->picker)->put(route('pick.record', [$list, $lineId]), $data);
    }

    /** @param  list<int>  $ticked  @param  list<int>  $unticked */
    private function tick(PickList $list, array $ticked, array $unticked = [])
    {
        return $this->actingAs($this->picker)->putJson(route('pick.progress', $list), ['ticked' => $ticked, 'unticked' => $unticked]);
    }

    private function pickEverything(PickList $list): void
    {
        foreach ($list->lines as $line) {
            $this->record($list, $line->id, ['qty_picked_base' => $line->qty_to_pick_base, 'override_reason' => 'scanner_fault'])->assertSessionHasNoErrors();
        }
    }

    /** A second order on the same run as $this->order. */
    private function secondOrder(): Order
    {
        return app(OrderService::class)->createManual($this->manager, [
            'customer_id' => Customer::factory()->create(['delivery_postcode' => '4019', 'delivery_suburb' => 'Clontarf'])->id,
            'site_id' => $this->site()->id, 'short_ship_policy' => 'backorder',
            'lines' => [['product_id' => $this->chilled->id, 'product_unit_id' => $this->chilled->units()->value('id'), 'qty' => 1]],
        ]);
    }

    /** @return list<int> */
    private function lineIds(PickList $list, Order $order): array
    {
        return $list->lines()->whereHas('orderLine', fn ($q) => $q->where('order_id', $order->id))->pluck('id')->all();
    }

    /** @param  array<int, int|null>  $cartons  order id => count */
    private function saveCartons(PickList $list, array $cartons)
    {
        return $this->actingAs($this->picker)->putJson(route('pick.progress', $list), ['cartons' => $cartons]);
    }

    private function cartonsOnPage(PickList $list, Order $order): ?int
    {
        $orders = $this->actingAs($this->picker)->get(route('pick.pack', $list))->assertOk()->inertiaProps('orders');

        return collect($orders)->firstWhere('id', $order->id)['cartons'];
    }

    /** @return list<int> Line ids the pack page shows as ticked, from a fresh page load. */
    private function ticksOnPage(PickList $list, Order $order): array
    {
        $orders = $this->actingAs($this->picker)->get(route('pick.pack', $list))->assertOk()->inertiaProps('orders');

        return collect(collect($orders)->firstWhere('id', $order->id)['lines'])->where('ticked', true)->pluck('id')->sort()->values()->all();
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
        $this->actingAs($other)->put(route('pick.record', [$list, $jam->id]), ['each' => 0])->assertSessionHasErrors('pick');
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
        $this->tick($list, array_slice($ids, 1))->assertNoContent();
        $this->actingAs($this->picker)->post(route('pick.pack.store', [$list, $this->order]), ['cartons' => 2])
            ->assertSessionHasErrors(['pick' => 'Tick every line as packed.']);
        $this->tick($list, $ids)->assertNoContent();
        $this->actingAs($this->picker)->post(route('pick.pack.store', [$list, $this->order]), ['cartons' => 2])->assertSessionHasNoErrors();

        $slip = $this->actingAs($this->picker)->get(route('pick.slip', [$list, $this->order]));
        $slip->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $slip->getContent());

        $this->actingAs($this->picker)->post(route('pick.dispatch', $this->run_()))->assertSessionHasNoErrors();
        $this->assertSame(OrderStatus::Dispatched, $this->order->fresh()->status);
        $this->assertSame(DeliveryRun::STATUS_DISPATCHED, $this->run_()->status);
        $this->actingAs($this->picker)->get(route('pick.manifest', $this->run_()))->assertInertia(fn (AssertableInertia $p) => $p
            ->component('warehouse/RunManifest')->where('orders.0.cartons', 2)->where('orders.0.packed', true));
    }

    public function test_packing_one_order_leaves_the_ticks_on_the_other_orders()
    {
        $orderB = $this->secondOrder();
        $list = $this->start();
        $this->pickEverything($list);
        $linesA = $this->lineIds($list, $this->order);
        $linesB = $this->lineIds($list, $orderB);
        $this->assertNotEmpty($linesB);

        $this->tick($list, [...$linesA, ...$linesB])->assertNoContent();
        $this->actingAs($this->picker)->post(route('pick.pack.store', [$list, $this->order]), ['cartons' => 2])->assertSessionHasNoErrors();

        $this->assertSame($linesB, $this->ticksOnPage($list, $orderB));
    }

    public function test_ticks_save_with_who_and_when_restore_on_load_and_untick_reverses()
    {
        $list = $this->start();
        $this->pickEverything($list);
        $ids = $this->lineIds($list, $this->order);

        $this->tick($list, [$ids[0], $ids[1]])->assertNoContent();
        $line = $list->lines()->find($ids[0]);
        $this->assertSame($this->picker->id, $line->ticked_by);
        $this->assertNotNull($line->ticked_at);
        $this->assertSame([$ids[0], $ids[1]], $this->ticksOnPage($list, $this->order));

        // A repeat tick, as a retry after a dropped connection sends, keeps the first who and when.
        $first = $line->ticked_at;
        Carbon::setTestNow(now()->addMinutes(5));
        $this->actingAs($this->manager)->putJson(route('pick.progress', $list), ['ticked' => [$ids[0]]])->assertNoContent();
        $this->assertEquals($first, $line->fresh()->ticked_at);
        $this->assertSame($this->picker->id, $line->fresh()->ticked_by);

        $this->tick($list, [], [$ids[0]])->assertNoContent();
        $this->assertNull($line->fresh()->ticked_at);
        $this->assertNull($line->fresh()->ticked_by);
        $this->assertSame([$ids[1]], $this->ticksOnPage($list, $this->order));
    }

    public function test_packed_is_final()
    {
        $list = $this->start();
        $this->pickEverything($list);
        $ids = $this->lineIds($list, $this->order);
        $this->tick($list, $ids)->assertNoContent();
        $this->actingAs($this->picker)->post(route('pick.pack.store', [$list, $this->order]), ['cartons' => 2])->assertSessionHasNoErrors();

        $this->tick($list, [], [$ids[0]])->assertUnprocessable()->assertJsonValidationErrors(['pick' => 'That order is already packed.']);
        $this->assertSame($ids, $this->ticksOnPage($list, $this->order));

        $this->actingAs($this->manager)->post(route('pick.pack.store', [$list, $this->order]), ['cartons' => 9])->assertSessionHasNoErrors();
        $pack = OrderPack::sole();
        $this->assertSame([2, $this->picker->id], [$pack->cartons, $pack->packed_by]);
        $this->assertSame(1, AuditLog::where('event', 'order_packed')->count());
    }

    public function test_ticks_are_refused_for_a_line_on_another_list_or_a_closed_list()
    {
        $list = $this->start();
        $this->tick($list, [999999])->assertUnprocessable()->assertJsonValidationErrors(['pick' => "That line is not on {$list->number}."]);

        $this->pickEverything($list);
        $ids = $this->lineIds($list, $this->order);
        $list->forceFill(['status' => PickList::STATUS_CANCELLED])->save();
        $this->tick($list, $ids)->assertUnprocessable()->assertJsonValidationErrors('pick');
        $this->assertSame(0, $list->lines()->whereNotNull('ticked_at')->count());
    }

    public function test_carton_counts_save_restore_on_load_and_can_be_changed_or_cleared()
    {
        $orderB = $this->secondOrder();
        $list = $this->start();
        $this->assertNull($this->cartonsOnPage($list, $this->order));

        $this->saveCartons($list, [$this->order->id => 3, $orderB->id => 1])->assertNoContent();
        $this->assertSame(3, $this->cartonsOnPage($list, $this->order));
        $this->assertSame(1, $this->cartonsOnPage($list, $orderB));

        $this->saveCartons($list, [$this->order->id => 0])->assertNoContent();
        $this->assertSame(0, $this->cartonsOnPage($list, $this->order));

        $this->saveCartons($list, [$this->order->id => null])->assertNoContent();
        $this->assertNull($this->cartonsOnPage($list, $this->order));
        $this->assertSame(1, $this->cartonsOnPage($list, $orderB));
        $this->assertSame(1, OrderPackDraft::count());
    }

    public function test_a_saved_carton_count_does_not_make_an_order_packed()
    {
        $list = $this->start();
        $this->pickEverything($list);
        $this->saveCartons($list, [$this->order->id => 3])->assertNoContent();

        $this->assertSame(0, OrderPack::count());
        $this->actingAs($this->picker)->post(route('pick.dispatch', $this->run_()))->assertSessionHasErrors(['pick' => "Pack every order on {$list->number} before dispatch."]);
        $this->actingAs($this->picker)->get(route('pick.manifest', $this->run_()))->assertInertia(fn (AssertableInertia $p) => $p
            ->where('orders.0.packed', false)->where('orders.0.cartons', 0));
        $orders = $this->actingAs($this->picker)->get(route('pick.pack', $list))->inertiaProps('orders');
        $this->assertNull($orders[0]['pack']);
    }

    public function test_the_carton_count_locks_once_packed()
    {
        $orderB = $this->secondOrder();
        $list = $this->start();
        $this->pickEverything($list);
        $this->tick($list, $this->lineIds($list, $this->order))->assertNoContent();
        $this->saveCartons($list, [$this->order->id => 3, $orderB->id => 2])->assertNoContent();

        $this->actingAs($this->picker)->post(route('pick.pack.store', [$list, $this->order]), ['cartons' => 3])->assertSessionHasNoErrors();
        $this->assertSame([$orderB->id], OrderPackDraft::pluck('order_id')->all());

        $this->saveCartons($list, [$this->order->id => 9])->assertUnprocessable()->assertJsonValidationErrors(['pick' => 'That order is already packed.']);
        $this->saveCartons($list, [$this->order->id => null])->assertUnprocessable();
        $this->assertSame(3, $this->cartonsOnPage($list, $this->order));
        $this->assertSame(3, OrderPack::sole()->cartons);

        // A refused batch changes nothing, including the other order on it.
        $this->saveCartons($list, [$orderB->id => 7, $this->order->id => 9])->assertUnprocessable();
        $this->assertSame(2, $this->cartonsOnPage($list, $orderB));
    }

    public function test_carton_counts_are_refused_when_invalid_off_the_list_or_on_a_closed_list()
    {
        $list = $this->start();
        foreach ([-1, 501, 1.5, 'x'] as $bad) {
            $this->saveCartons($list, [$this->order->id => $bad])->assertUnprocessable();
        }
        $this->saveCartons($list, [999999 => 1])->assertUnprocessable()->assertJsonValidationErrors(['pick' => "That order is not on {$list->number}."]);

        $list->forceFill(['status' => PickList::STATUS_CANCELLED])->save();
        $this->saveCartons($list, [$this->order->id => 1])->assertUnprocessable()->assertJsonValidationErrors('pick');
        $this->assertSame(0, OrderPackDraft::count());
    }

    public function test_changing_a_picked_quantity_clears_its_tick()
    {
        $list = $this->start();
        $this->pickEverything($list);
        $ids = $this->lineIds($list, $this->order);
        $this->tick($list, $ids)->assertNoContent();

        $line = $list->lines()->where('product_id', $this->ambient->id)->first();
        $this->record($list, $line->id, ['qty_picked_base' => $line->qty_to_pick_base, 'override_reason' => 'scanner_fault'])->assertSessionHasNoErrors();
        $this->assertNotNull($line->fresh()->ticked_at);

        $this->record($list, $line->id, ['qty_picked_base' => 0])->assertSessionHasNoErrors();
        $this->assertNull($line->fresh()->ticked_at);
    }

    public function test_every_pick_page_renders_the_nav_bar_with_a_route_one_level_up()
    {
        $list = $this->start();
        $run = $this->run_();

        $pages = [
            [route('pick.queue'), 'warehouse/PickQueue', 'Pick & pack (phone/handheld)', route('dashboard')],
            [route('pick.work', $list), 'warehouse/PickWork', "{$list->number} · {$run->label()}", route('pick.queue')],
            [route('pick.pack', $list), 'warehouse/Pack', "Pack {$list->number}", route('pick.work', $list)],
            [route('pick.manifest', $run), 'warehouse/RunManifest', $run->label(), route('pick.queue')],
        ];

        foreach ($pages as [$url, $component, $title, $back]) {
            $this->actingAs($this->picker)->get($url)->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
                ->component($component)
                ->where('nav.title', $title)
                ->where('nav.back', $back)
                ->where('nav.links', [
                    ['title' => 'Dashboard', 'href' => route('dashboard')],
                    ['title' => 'Pick & pack (phone/handheld)', 'href' => route('pick.queue')],
                    ['title' => 'Pick lists (desktop)', 'href' => route('pick-lists.index')],
                    ['title' => 'Orders', 'href' => route('orders.index')],
                ]));
        }
    }

    public function test_nav_links_are_filtered_by_permission_like_the_sidebar()
    {
        // Every seeded role that can pick can also view orders, so this needs a narrower role.
        Role::findOrCreate('picker_only', 'web')->givePermissionTo(Permissions::PICKING_PERFORM);
        $pickerOnly = User::factory()->create();
        $pickerOnly->assignRole('picker_only');

        $list = $this->start();
        $this->actingAs($pickerOnly)->get(route('pick.work', $list))->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->where('nav.links', [
                ['title' => 'Dashboard', 'href' => route('dashboard')],
                ['title' => 'Pick & pack (phone/handheld)', 'href' => route('pick.queue')],
                ['title' => 'Pick lists (desktop)', 'href' => route('pick-lists.index')],
            ]));
        $this->actingAs($pickerOnly)->get(route('orders.index'))->assertForbidden();
    }

    public function test_leaving_a_claimed_list_keeps_the_claim_and_take_over_still_works()
    {
        $list = $this->start();
        $this->assertSame($this->picker->id, $list->claimed_by);
        $claimedAt = $list->claimed_at;

        foreach ([route('pick.queue'), route('dashboard'), route('pick-lists.index'), route('orders.index')] as $url) {
            $this->actingAs($this->picker)->get($url)->assertOk();
        }
        $this->assertSame($this->picker->id, $list->fresh()->claimed_by);
        $this->assertEquals($claimedAt, $list->fresh()->claimed_at);
        $this->actingAs($this->picker)->get(route('pick.queue'))->assertInertia(fn (AssertableInertia $p) => $p
            ->where('runs.0.lists.0.mine', true));

        $other = $this->userWithRole(Permissions::ROLE_WAREHOUSE_STAFF);
        $this->actingAs($other)->post(route('pick.claim', $list), ['take_over' => true])->assertRedirect(route('pick.work', $list));
        $this->assertSame($other->id, $list->fresh()->claimed_by);
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

    private function binScanSetting(bool $on): void
    {
        $this->actingAs($this->userWithRole(Permissions::ROLE_ADMIN))->put(route('admin.settings.update'), ['company_name' => 'Local Selection', 'require_bin_scan' => $on])->assertSessionHasNoErrors();
    }

    public function test_the_bin_scan_is_required_by_default()
    {
        $this->assertTrue(Setting::requiresBinScan());
        $list = $this->start();
        $line = $list->lines()->where('product_id', $this->ambient->id)->first();

        $this->record($list, $line->id, ['qty_picked_base' => 6, 'product_barcodes' => ['9300000000019']])->assertSessionHasErrors('pick');

        $this->assertNull($line->fresh()->qty_picked_base);
    }

    public function test_with_the_setting_off_the_product_scan_alone_confirms_a_line()
    {
        $this->binScanSetting(false);
        $list = $this->start();
        $line = $list->lines()->where('product_id', $this->ambient->id)->first();

        $this->record($list, $line->id, ['qty_picked_base' => 6, 'product_barcodes' => ['9300000000019']])->assertSessionHasNoErrors();

        $line->refresh();
        $this->assertSame(6, $line->qty_picked_base);
        $this->assertNull($line->bin_scanned_at);
        $this->assertNotNull($line->product_scanned_at);
        $this->assertNull($line->override_reason);
        $this->assertSame(0, AuditLog::where('event', 'pick_scan_override')->count());
    }

    public function test_with_the_setting_off_a_line_still_needs_the_product_scan_or_a_reason()
    {
        $this->binScanSetting(false);
        $list = $this->start();
        $line = $list->lines()->where('product_id', $this->ambient->id)->first();

        $this->record($list, $line->id, ['qty_picked_base' => 6])->assertSessionHasErrors('pick');
        $this->record($list, $line->id, ['qty_picked_base' => 6, 'override_reason' => 'scanner_fault'])->assertSessionHasNoErrors();

        $this->assertSame('scanner_fault', $line->fresh()->override_reason);
    }

    public function test_with_the_setting_off_a_wrong_bin_scanned_anyway_is_still_refused()
    {
        $this->binScanSetting(false);
        $list = $this->start();
        $line = $list->lines()->where('product_id', $this->ambient->id)->first();

        $this->record($list, $line->id, ['qty_picked_base' => 6, 'bin_barcode' => 'NOT-THIS-BIN', 'product_barcodes' => ['9300000000019']])->assertSessionHasErrors('pick');
    }

    public function test_the_pick_screen_is_told_the_setting_and_only_admins_change_it()
    {
        $list = $this->start();
        $this->actingAs($this->picker)->get(route('pick.work', $list))->assertInertia(fn (AssertableInertia $p) => $p->where('requireBinScan', true));

        $this->binScanSetting(false);

        $this->actingAs($this->picker)->get(route('pick.work', $list))->assertInertia(fn (AssertableInertia $p) => $p->where('requireBinScan', false));
        $this->actingAs($this->manager)->put(route('admin.settings.update'), ['company_name' => 'X', 'require_bin_scan' => true])->assertForbidden();
    }

    public function test_saving_company_details_without_the_field_leaves_the_setting_alone()
    {
        $this->binScanSetting(false);

        $this->actingAs($this->userWithRole(Permissions::ROLE_ADMIN))->put(route('admin.settings.update'), ['company_name' => 'Local Selection'])->assertSessionHasNoErrors();

        $this->assertFalse(Setting::requiresBinScan());
    }
}
