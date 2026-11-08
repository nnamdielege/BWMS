<?php

namespace Tests\Feature\Orders;

use App\Authorization\Permissions;
use App\Models\Customer;
use App\Models\Order;
use App\Models\PickList;
use App\Models\Product;
use App\Models\ReasonCode;
use App\Models\User;
use App\Services\Orders\OrderService;
use App\Services\Orders\PickListService;
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
        // As it happened: "5 cases" typed into a box that took bottles.
        [$a, $b, $c] = [$this->wine('ETN-IT-ROSSO'), $this->wine('ETN-IT-BIANCO'), $this->wine('ETN-IT-ROSATO')];
        foreach ([[$a, 5], [$b, 5], [$c, 4]] as [$p, $typed]) {
            $this->adjustIn($p, ['each' => $typed]);
        }
        $order = $this->orderOneCaseEach([$a, $b, $c], allowBackorder: true);
        $list = $this->pickList($order);
        // Everything there was picked; the bottles with no stock are the short lines.
        app(PickListService::class)->recordPicked($list, $list->lines()->get()->mapWithKeys(fn ($l) => [$l->id => $l->no_stock ? 0 : $l->qty_to_pick_base])->all());
        app(PickListService::class)->dispatch($list, $this->picker);

        $lines = $order->lines()->with('reservation')->get()->keyBy('product_id');
        $this->assertSame([5, 5, 4], [$lines[$a->id]->qty_dispatched_base, $lines[$b->id]->qty_dispatched_base, $lines[$c->id]->qty_dispatched_base]);
        $this->assertSame([7, 7, 8], [$lines[$a->id]->reservation->qty_base, $lines[$b->id]->reservation->qty_base, $lines[$c->id]->reservation->qty_base]);

        // The report finds it: whole packs between dispatched and reserved, but bottles already shipped.
        Artisan::call('orders:pack-mismatches');
        $report = Artisan::output();
        $this->assertStringContainsString('Part of a pack already dispatched: 3 line(s) on 1 order(s).', $report);
        $this->assertStringContainsString('Merak Gourmet', $report);
        $this->assertStringContainsString('5 bottles (5)', $report);
        $this->assertStringContainsString("isn't a whole number of packs: 0 line(s)", $report);

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
