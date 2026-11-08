<?php

namespace Tests\Feature\Orders;

use App\Authorization\Permissions;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Order;
use App\Models\PickList;
use App\Models\Product;
use App\Models\ReasonCode;
use App\Models\ReceiptLine;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Orders\OrderService;
use App\Services\Orders\PickListService;
use App\Services\Purchasing\PurchaseOrderService;
use App\Services\Stock\Exceptions\InvalidMovement;
use App\Services\Stock\StocktakeExport;
use App\Services\Stock\StocktakeService;
use App\Support\PackQty;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Support\StockFixtures;
use Tests\TestCase;

/**
 * Quantities are typed as packs + loose units and saved as base units, on every
 * screen that takes one. Before, the boxes took base units, so "1" typed for a
 * Case of 12 was saved as 1 bottle.
 */
class PackQuantityTest extends TestCase
{
    use RefreshDatabase, StockFixtures;

    private User $manager;

    private User $picker;

    private Customer $merak;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedStockReferenceData();
        $this->manager = $this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER);
        $this->picker = $this->userWithRole(Permissions::ROLE_WAREHOUSE_STAFF);
        $this->merak = Customer::factory()->create(['name' => 'Merak Gourmet']);
    }

    private function wine(string $sku): Product
    {
        return Product::factory()->withPack('Case', 12)->create(['sku' => $sku, 'name' => "Etna {$sku}", 'base_unit' => 'bottle']);
    }

    private function caseOf(Product $p): int
    {
        return $p->units()->where('base_qty', 12)->value('id');
    }

    /** @param  list<Product>  $products  one Case of each */
    private function orderOneCaseEach(array $products, bool $allowBackorder = false): Order
    {
        return app(OrderService::class)->createManual($this->manager, [
            'customer_id' => $this->merak->id, 'site_id' => $this->site()->id, 'short_ship_policy' => 'backorder',
            'lines' => array_map(fn (Product $p) => ['product_id' => $p->id, 'product_unit_id' => $this->caseOf($p), 'qty' => 1], $products),
        ], $allowBackorder);
    }

    private function pickList(Order $order): PickList
    {
        return app(PickListService::class)->create($this->picker, $this->site(), [$order->id])['pickList'];
    }

    private function expectNothingPickable(Order $order): void
    {
        try {
            $this->pickList($order);
            $this->fail('A pick list was made from less than a pack.');
        } catch (InvalidMovement $e) {
            $this->assertStringContainsString('Nothing can be picked', $e->getMessage());
        }
    }

    private function adjustIn(Product $p, array $qty): void
    {
        $this->actingAs($this->manager)->post(route('stock.adjust', $p), $qty + [
            'client_token' => (string) Str::uuid(), 'location_id' => $this->bin()->id, 'direction' => 'in',
            'reason_code_id' => ReasonCode::where('direction', '!=', 'out')->value('id'),
        ])->assertSessionHasNoErrors();
    }

    /**
     * SO for Merak Gourmet: 1 × Case(12) of three ETN-IT wines. Stock had gone in
     * as 5, 5 and 4 meaning cases, saved as bottles, so the pick shipped 5/5/4
     * bottles and left the rest reserved and re-queued.
     */
    public function test_the_merak_gourmet_order_as_it_happened_and_as_it_goes_now()
    {
        // "5 cases" typed into a box that took bottles.
        [$a, $b, $c] = [$this->wine('ETN-IT-ROSSO'), $this->wine('ETN-IT-BIANCO'), $this->wine('ETN-IT-ROSATO')];
        foreach ([[$a, 5], [$b, 5], [$c, 4]] as [$p, $typed]) {
            $this->adjustIn($p, ['each' => $typed]);
        }
        $order = $this->orderOneCaseEach([$a, $b, $c], allowBackorder: true);

        // Pick lists now plan whole Cases only, so 5 loose bottles don't go out as a Case order: it waits.
        $readiness = app(PickListService::class)->readiness(collect([$order->fresh('lines.product', 'lines.unit')]), $this->site());
        $this->assertCount(0, $readiness['ready']);
        $this->assertSame(['1 Case', '1 Case', '1 Case'], array_column($readiness['waiting'][0]['lines'], 'short'));
        $this->expectNothingPickable($order);

        // As it happened under the old planner: 5/5/4 bottles out, 7/7/8 still reserved.
        foreach ([[$a, 5], [$b, 5], [$c, 4]] as [$p, $shipped]) {
            $line = $order->lines()->where('product_id', $p->id)->sole();
            $line->forceFill(['qty_dispatched_base' => $shipped])->save();
            $line->reservation->forceFill(['qty_base' => 12 - $shipped])->save();
        }
        $order->forceFill(['status' => 'partially_dispatched'])->save();

        // The report finds it: whole packs between dispatched and reserved, but bottles already shipped.
        Artisan::call('orders:pack-mismatches');
        $report = Artisan::output();
        $this->assertStringContainsString('Part of a pack already dispatched: 3 line(s) on 1 order(s).', $report);
        $this->assertStringContainsString('Merak Gourmet', $report);
        $this->assertStringContainsString('5 bottles (5)', $report);
        $this->assertStringContainsString("isn't a whole number of packs: 0 line(s)", $report);

        // With the stock counted right, the rest of each Case is picked to finish it.
        foreach ([$a, $b, $c] as $p) {
            $this->adjustIn($p, ['product_unit_id' => $this->caseOf($p), 'packs' => 2]);
        }
        $list = $this->pickList($order);
        $this->assertSame([7, 7, 8], $list->lines()->orderBy('id')->pluck('qty_to_pick_base')->all());
        app(PickListService::class)->recordPicked($list, $list->lines()->pluck('qty_to_pick_base', 'id')->all());
        app(PickListService::class)->dispatch($list, $this->picker);
        $this->assertSame([12, 12, 12], $order->lines()->orderBy('id')->pluck('qty_dispatched_base')->all());
        $this->assertSame('dispatched', $order->fresh()->status->value);

        // As it goes now: 5 cases typed as 5 cases, and 1 Case picked as 1 Case.
        [$x, $y, $z] = [$this->wine('ETN-IT-NERO'), $this->wine('ETN-IT-BLANC'), $this->wine('ETN-IT-ROSE')];
        foreach ([[$x, 5], [$y, 5], [$z, 4]] as [$p, $cases]) {
            $this->adjustIn($p, ['product_unit_id' => $this->caseOf($p), 'packs' => $cases]);
        }
        $this->assertSame([60, 60, 48], [$this->balance($x), $this->balance($y), $this->balance($z)]);

        $order = $this->orderOneCaseEach([$x, $y, $z]);
        $list = $this->pickList($order);
        $this->actingAs($this->picker)->put(route('pick-lists.record', $list), [
            'picked' => $list->lines()->pluck('id')->mapWithKeys(fn ($id) => [$id => ['packs' => 1]])->all(),
        ])->assertSessionHasNoErrors();
        $this->actingAs($this->picker)->post(route('pick-lists.dispatch', $list))->assertSessionHasNoErrors();

        foreach ($order->lines()->with('reservation')->get() as $line) {
            $this->assertSame(12, $line->qty_dispatched_base);
            $this->assertSame(0, (int) $line->reservation?->qty_base);
        }
        $this->assertSame('dispatched', $order->fresh()->status->value);
        $this->assertSame(1, PickList::whereHas('lines.orderLine', fn ($q) => $q->where('order_id', $order->id))->count(), 'one pick list, nothing re-queued');
    }

    public function test_the_desktop_pick_list_takes_packs_and_shows_the_pack()
    {
        $wine = $this->wine('ETN-IT-ROSSO');
        $this->stockIn($wine, 48);
        $list = $this->pickList(app(OrderService::class)->createManual($this->manager, [
            'customer_id' => $this->merak->id, 'site_id' => $this->site()->id, 'short_ship_policy' => 'backorder',
            'lines' => [['product_id' => $wine->id, 'product_unit_id' => $this->caseOf($wine), 'qty' => 2]],
        ]));
        $line = $list->lines()->sole();

        $this->actingAs($this->picker)->get(route('pick-lists.show', $list))->assertInertia(fn (AssertableInertia $p) => $p
            ->where('pickList.lines.0.pack', ['per' => 12, 'name' => 'Case', 'base_unit' => 'bottle'])
            ->where('pickList.lines.0.to_pick_label', '2 Case'));

        $this->actingAs($this->picker)->put(route('pick-lists.record', $list), ['picked' => [$line->id => ['packs' => 1, 'each' => 5]]])->assertSessionHasNoErrors();
        $this->assertSame(17, $line->fresh()->qty_picked_base);

        $this->actingAs($this->picker)->put(route('pick-lists.record', $list), ['picked' => [$line->id => ['packs' => null, 'each' => null]]])->assertSessionHasNoErrors();
        $this->assertSame(17, $line->fresh()->qty_picked_base, 'blank boxes leave the line alone');

        $this->actingAs($this->picker)->put(route('pick-lists.record', $list), ['picked' => [$line->id => ['packs' => 3]]])->assertSessionHasErrors('pick');
    }

    public function test_the_handheld_enter_quantity_and_short_pick_take_packs()
    {
        $wine = $this->wine('ETN-IT-ROSSO');
        $this->stockIn($wine, 48);
        $list = $this->pickList(app(OrderService::class)->createManual($this->manager, [
            'customer_id' => $this->merak->id, 'site_id' => $this->site()->id, 'short_ship_policy' => 'backorder',
            'lines' => [['product_id' => $wine->id, 'product_unit_id' => $this->caseOf($wine), 'qty' => 2]],
        ]));
        $line = $list->lines()->sole();

        $this->actingAs($this->picker)->get(route('pick.work', $list))->assertInertia(fn (AssertableInertia $p) => $p
            ->where('lines.0.pack', ['per' => 12, 'name' => 'Case', 'base_unit' => 'bottle'])
            ->where('lines.0.to_pick_label', '2 Case'));

        $record = fn (array $qty) => $this->actingAs($this->picker)->put(route('pick.record', [$list, $line]), $qty + ['override_reason' => 'label_missing']);

        $record(['packs' => 1])->assertSessionHasNoErrors();
        $this->assertSame(12, $line->fresh()->qty_picked_base, '1 Case is 12 bottles, not 1');

        $record(['packs' => 0, 'each' => 5])->assertSessionHasNoErrors(); // short: found 5 bottles
        $this->assertSame(5, $line->fresh()->qty_picked_base);

        $record([])->assertSessionHasErrors(['qty' => 'Enter how many you picked.']);
        $this->actingAs($this->picker)->put(route('pick.record', [$list, $line]), ['qty_picked_base' => 12, 'override_reason' => 'label_missing'])
            ->assertSessionHasErrors('qty');
        $this->assertSame(5, $line->fresh()->qty_picked_base, 'a bare number is no longer taken');
    }

    public function test_the_stocktake_counts_in_the_largest_pack()
    {
        $wine = $this->wine('ETN-IT-ROSSO');
        $this->stockIn($wine, 36);
        $st = app(StocktakeService::class)->create($this->manager, $this->bin());
        $line = $st->lines()->sole();

        $this->actingAs($this->picker)->get(route('stocktakes.show', $st))->assertInertia(fn (AssertableInertia $p) => $p
            ->where('lines.0.pack', ['per' => 12, 'name' => 'Case', 'base_unit' => 'bottle']));

        $this->actingAs($this->picker)->put(route('stocktakes.count', [$st, $line]), ['packs' => 3, 'each' => 4])->assertSessionHasNoErrors();
        $this->assertSame(40, $line->fresh()->counted_qty);

        $this->actingAs($this->picker)->put(route('stocktakes.count', [$st, $line]), [])->assertSessionHasErrors('stocktake');
    }

    public function test_adjust_and_transfer_take_a_pack_of_the_product()
    {
        $wine = $this->wine('ETN-IT-ROSSO');
        $other = $this->wine('OTHER');

        $this->adjustIn($wine, ['product_unit_id' => $this->caseOf($wine), 'packs' => 2, 'each' => 1]);
        $this->assertSame(25, $this->balance($wine));

        $this->actingAs($this->manager)->post(route('stock.adjust', $wine), [
            'client_token' => (string) Str::uuid(), 'location_id' => $this->bin()->id, 'direction' => 'out', 'product_unit_id' => $this->caseOf($wine), 'packs' => 1,
            'reason_code_id' => ReasonCode::where('direction', '!=', 'in')->value('id'), 'note' => 'Broken',
        ])->assertSessionHasNoErrors();
        $this->assertSame(13, $this->balance($wine));

        $this->actingAs($this->manager)->post(route('stock.adjust', $wine), [
            'client_token' => (string) Str::uuid(), 'location_id' => $this->bin()->id, 'direction' => 'in', 'product_unit_id' => $this->caseOf($other), 'packs' => 1,
            'reason_code_id' => ReasonCode::where('direction', '!=', 'out')->value('id'),
        ])->assertSessionHasErrors('product_unit_id');
        $this->actingAs($this->manager)->post(route('stock.adjust', $wine), [
            'client_token' => (string) Str::uuid(), 'location_id' => $this->bin()->id, 'direction' => 'in', 'packs' => 0,
            'reason_code_id' => ReasonCode::where('direction', '!=', 'out')->value('id'),
        ])->assertSessionHasErrors(['qty' => 'Enter a quantity.']);

        $this->actingAs($this->picker)->post(route('stock.transfer', $wine), [
            'client_token' => (string) Str::uuid(), 'from_location_id' => $this->bin()->id, 'to_location_id' => $this->bin('QUARANTINE')->id,
            'product_unit_id' => $this->caseOf($wine), 'packs' => 1,
        ])->assertSessionHasNoErrors();
        $this->assertSame(12, $this->balance($wine, $this->bin('QUARANTINE')));
    }

    public function test_the_order_page_has_the_pack_for_reserved_and_dispatched()
    {
        $wine = $this->wine('ETN-IT-ROSSO');
        $this->stockIn($wine, 5);
        $order = $this->orderOneCaseEach([$wine], allowBackorder: true);

        $this->actingAs($this->manager)->get(route('orders.show', $order))->assertInertia(fn (AssertableInertia $p) => $p
            ->where('order.lines.0.pack', ['per' => 12, 'name' => 'Case', 'base_unit' => 'bottle'])
            ->where('order.lines.0.reserved', 12));
    }

    public function test_the_report_lists_dispatched_plus_reserved_that_isnt_whole_packs()
    {
        $wine = $this->wine('ETN-IT-ROSSO');
        $this->stockIn($wine, 60);
        $order = $this->orderOneCaseEach([$wine]);
        $order->lines()->sole()->forceFill(['qty_dispatched_base' => 5])->save(); // 5 out, 12 still reserved

        Artisan::call('orders:pack-mismatches');
        $report = Artisan::output();

        $this->assertStringContainsString("isn't a whole number of packs: 1 line(s) on 1 order(s).", $report);
        $this->assertStringContainsString('1 Case + 5 bottles (17)', $report);
    }

    public function test_each_bin_gives_whole_packs_only()
    {
        $wine = $this->wine('ETN-IT-ROSSO');
        $other = Location::create(['parent_id' => $this->site()->id, 'type' => 'bin', 'code' => 'B-2', 'name' => 'B-2', 'is_sellable' => true]);
        $this->stockIn($wine, 17);          // 1 Case + 5 loose
        $this->stockIn($wine, 7, $other);   // less than a Case
        $order = app(OrderService::class)->createManual($this->manager, [
            'customer_id' => $this->merak->id, 'site_id' => $this->site()->id, 'short_ship_policy' => 'backorder',
            'lines' => [['product_id' => $wine->id, 'product_unit_id' => $this->caseOf($wine), 'qty' => 2]],
        ]);

        $list = $this->pickList($order);

        $picks = $list->lines()->where('no_stock', false)->get();
        $this->assertSame([12], $picks->pluck('qty_to_pick_base')->all(), '12 + 12 loose is still not a second Case');
        $this->assertSame($this->bin()->id, $picks->first()->location_id);
        $this->assertSame(12, $list->lines()->where('no_stock', true)->sole()->qty_to_pick_base, 'the second Case waits for stock');
    }

    public function test_lines_sold_by_the_unit_are_unchanged()
    {
        $tea = Product::factory()->create(['sku' => 'TEA']);
        $this->stockIn($tea, 5);
        $order = app(OrderService::class)->createManual($this->manager, [
            'customer_id' => $this->merak->id, 'site_id' => $this->site()->id, 'short_ship_policy' => 'backorder',
            'lines' => [['product_id' => $tea->id, 'product_unit_id' => $tea->units()->value('id'), 'qty' => 7]],
        ], allowBackorder: true);

        $this->assertSame(5, $this->pickList($order)->lines()->where('no_stock', false)->sole()->qty_to_pick_base);
    }

    public function test_receiving_takes_damaged_and_rejected_in_packs_too()
    {
        $wine = $this->wine('ETN-IT-ROSSO');
        $service = app(PurchaseOrderService::class);
        $po = $service->markSent($service->saveDraft(null, [
            'supplier_id' => Supplier::factory()->create()->id, 'site_id' => $this->site()->id, 'order_date' => '2026-11-02',
            'lines' => [['product_id' => $wine->id, 'product_unit_id' => $this->caseOf($wine), 'qty_ordered' => 3, 'unit_cost' => '120.00']],
        ], $this->manager), $this->manager);

        $this->actingAs($this->manager)->post(route('purchase-orders.receipts.store', $po), [
            'client_token' => (string) Str::uuid(),
            'lines' => [[
                'purchase_order_line_id' => $po->lines()->value('id'),
                'accepted' => ['packs' => 2], 'damaged' => ['packs' => 0, 'each' => 6], 'rejected' => ['each' => 6],
                'discrepancy_reason' => 'damaged',
            ]],
        ])->assertSessionHasNoErrors();

        $line = ReceiptLine::sole();
        $this->assertSame([24, 6, 6], [$line->qty_accepted_base, $line->qty_damaged_base, $line->qty_rejected_base]);
    }

    public function test_stocktake_variances_keep_the_cost_they_were_posted_at()
    {
        $wine = $this->wine('ETN-IT-ROSSO');
        $wine->forceFill(['avg_cost' => '2.5000'])->save();
        $this->stockIn($wine, 24);
        $st = app(StocktakeService::class)->create($this->manager, $this->bin());
        app(StocktakeService::class)->recordCount($st->lines()->sole(), 20, $this->manager);

        app(StocktakeService::class)->post($st, $this->manager);
        $wine->forceFill(['avg_cost' => '9.0000'])->save(); // a later receipt at a new price

        $this->assertSame('2.5000', StockMovement::where('source_type', 'stocktake')->sole()->unit_cost);
        $file = app(StocktakeExport::class)->export($st->fresh(), StocktakeExport::RESULTS, 'csv', true);
        $this->assertStringContainsString(',2.5,-10,', $file['body'], 'unit cost and variance $ as posted, not at today\'s 9.00');
    }

    public function test_labels()
    {
        $this->assertSame('1 Case', PackQty::label(12, 12, 'Case', 'bottle'));
        $this->assertSame('1 Case + 5 bottles', PackQty::label(17, 12, 'Case', 'bottle'));
        $this->assertSame('1 bottle', PackQty::label(1, 12, 'Case', 'bottle'));
        $this->assertSame('0 Case', PackQty::label(0, 12, 'Case', 'bottle'));
        $this->assertSame('−1 Case + 2 each', PackQty::label(-14, 12, 'Case', 'each'));
        $this->assertSame('5 units', PackQty::label(5, 1, 'unit', 'unit'));
        $this->assertSame('1 Case = 12 each', PackQty::hint(12, 'Case', 'each'));
        $this->assertSame(17, PackQty::toBase(['packs' => '1', 'each' => '5'], 12));
        $this->assertSame(5, PackQty::toBase(['each' => 5], 1));
    }
}
