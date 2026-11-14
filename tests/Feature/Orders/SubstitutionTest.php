<?php

namespace Tests\Feature\Orders;

use App\Authorization\Permissions;
use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Integrations\Portal\PortalClient;
use App\Models\AuditLog;
use App\Models\ChannelListing;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\OrderSubstitution;
use App\Models\Product;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Documents\PdfRenderer;
use App\Services\Orders\OrderService;
use App\Services\Orders\PickListService;
use App\Services\Reports\DropReport;
use Database\Seeders\DeliveryAreaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\Support\OrderFixtures;
use Tests\Support\StockFixtures;
use Tests\TestCase;

/** A customer accepts another product in place of an oversold or short line. */
class SubstitutionTest extends TestCase
{
    use OrderFixtures, RefreshDatabase, StockFixtures;

    private User $manager;

    private User $picker;

    private Product $tea;

    private Product $coffee;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-14 09:00', 'Australia/Brisbane')); // Saturday
        $this->seedStockReferenceData();
        $this->seed(DeliveryAreaSeeder::class);
        $this->manager = $this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER);
        $this->picker = $this->userWithRole(Permissions::ROLE_WAREHOUSE_STAFF);
        // Sell prices are per each (the default sale pack); both also come by the carton of 6.
        $this->tea = Product::factory()->withPack('Carton', 6)->create(['sku' => 'TEA', 'name' => 'Tea', 'sell_price' => 5]);
        $this->coffee = Product::factory()->withPack('Carton', 6)->create(['sku' => 'COF', 'name' => 'Coffee', 'sell_price' => 4]);
        $this->stockIn($this->coffee, 24);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function unitId(Product $p, int $baseQty): int
    {
        return $p->units()->where('base_qty', $baseQty)->value('id');
    }

    /** Tea ordered at $30 a carton, beyond stock (none), so oversold. */
    private function teaOrder(int $cartons = 2, string $policy = 'backorder'): Order
    {
        $customer = Customer::factory()->create(['delivery_postcode' => '4019', 'delivery_suburb' => 'Clontarf']);

        return app(OrderService::class)->createManual($this->manager, [
            'customer_id' => $customer->id, 'site_id' => $this->site()->id, 'short_ship_policy' => $policy,
            'lines' => [['product_id' => $this->tea->id, 'product_unit_id' => $this->unitId($this->tea, 6), 'qty' => $cartons, 'unit_price' => '30']],
        ], allowBackorder: true);
    }

    private function substitute(Order $order, OrderLine $line, array $replace, array $qty, array $extra = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->manager)->post(route('orders.substitute', [$order, $line]), $extra + [
            'replace' => $replace,
            'product_id' => $this->coffee->id,
            'product_unit_id' => $this->unitId($this->coffee, 1),
            'qty' => $qty,
            'note' => 'Accepted by Jane by phone 14/11',
        ]);
    }

    private function reserved(OrderLine $line): int
    {
        return (int) $line->fresh()->reservation?->qty_base;
    }

    private function subLine(Order $order): ?OrderLine
    {
        return $order->lines()->whereNotNull('substitute_for_line_id')->first();
    }

    public function test_substituting_the_whole_line_cancels_it_as_substituted_and_reserves_the_substitute()
    {
        $order = $this->teaOrder();
        $this->assertTrue($order->oversold);
        $tea = $order->lines()->sole();

        $this->substitute($order, $tea, ['packs' => 2], ['each' => 12])->assertSessionHasNoErrors()->assertSessionHas('success');

        $tea->refresh();
        $this->assertSame(12, $tea->qty_cancelled_base);
        $this->assertSame(12, $tea->qty_substituted_base);
        $this->assertSame(0, $tea->outstandingBase());
        $this->assertSame(0, $this->reserved($tea));

        $sub = $this->subLine($order);
        $this->assertSame($tea->id, $sub->substitute_for_line_id);
        $this->assertSame($this->coffee->id, $sub->product_id);
        $this->assertSame(12, $sub->qty_base);
        $this->assertSame(12, $this->reserved($sub));
        $this->assertSame('4.0000', $sub->unit_price); // the coffee's sell price per each

        // The original's shortfall is gone, so the oversold flag comes off by itself.
        $this->assertFalse($order->fresh()->oversold);
        $this->assertSame(1, AuditLog::where('event', 'order_oversold_cleared')->where('auditable_id', $order->id)->count());

        $s = OrderSubstitution::sole();
        $this->assertSame('-12.00', $s->price_difference); // $48 of coffee for $60 of tea
        $audit = AuditLog::where('event', 'order_line_substituted')->sole();
        $this->assertSame($this->manager->id, $audit->user_id);
        $this->assertSame('TEA', $audit->old_values['sku']);
        $this->assertSame('2 Carton', $audit->old_values['qty']);
        $this->assertSame('COF', $audit->new_values['sku']);
        $this->assertSame('Accepted by Jane by phone 14/11', $audit->meta['note']);
        $this->assertSame('-12.00', (string) $audit->meta['price_difference']);
    }

    public function test_substituting_part_of_a_line_leaves_the_rest_ordered()
    {
        $order = $this->teaOrder();
        $tea = $order->lines()->sole();

        $this->substitute($order, $tea, ['packs' => 1], ['each' => 6])->assertSessionHasNoErrors();

        $tea->refresh();
        $this->assertSame(6, $tea->qty_substituted_base);
        $this->assertSame(6, $tea->outstandingBase());
        $this->assertSame(6, $this->reserved($tea));
        $this->assertSame(6, $this->reserved($this->subLine($order)));
        $this->assertTrue($order->fresh()->oversold, 'still short of the tea that is left');
    }

    public function test_a_note_is_required_and_no_more_than_is_left_can_be_substituted()
    {
        $order = $this->teaOrder();
        $tea = $order->lines()->sole();

        $this->substitute($order, $tea, ['packs' => 1], ['each' => 6], ['note' => ''])->assertSessionHasErrors('note');
        $this->substitute($order, $tea, ['packs' => 3], ['each' => 6])->assertSessionHasErrors('substitute');
        $this->substitute($order, $tea, ['packs' => 1], ['each' => 6], as: $this->picker)->assertForbidden();
        $this->assertSame(0, OrderSubstitution::count());
    }

    public function test_keep_original_price_keeps_the_customers_total_the_same()
    {
        $order = $this->teaOrder();
        $tea = $order->lines()->sole();

        // 2 cartons of tea at $30 ($60) for 1 carton of coffee: $60 a carton.
        $this->substitute($order, $tea, ['packs' => 2], ['packs' => 1], [
            'product_unit_id' => $this->unitId($this->coffee, 6), 'keep_original_price' => true, 'unit_price' => '1',
        ])->assertSessionHasNoErrors();

        $sub = $this->subLine($order);
        $this->assertSame(6, $sub->qty_base);
        $this->assertSame(1, $sub->qty);
        $this->assertSame('60.0000', $sub->unit_price);
        $this->assertSame('0.00', OrderSubstitution::sole()->price_difference);
        $this->assertTrue(OrderSubstitution::sole()->kept_original_price);
    }

    public function test_a_typed_price_is_used_as_entered()
    {
        $order = $this->teaOrder();
        $this->substitute($order, $order->lines()->sole(), ['packs' => 1], ['packs' => 1], ['product_unit_id' => $this->unitId($this->coffee, 6), 'unit_price' => '33.5'])
            ->assertSessionHasNoErrors();

        $this->assertSame('33.5000', $this->subLine($order)->unit_price);
        $this->assertSame('3.50', OrderSubstitution::sole()->price_difference);
    }

    public function test_a_substitute_short_of_stock_is_allowed_with_a_warning_and_waits_like_any_line()
    {
        $order = $this->teaOrder();
        $order->forceFill(['oversold' => false])->save();

        $this->substitute($order, $order->lines()->sole(), ['packs' => 2], ['each' => 30])
            ->assertSessionHasNoErrors()->assertSessionHas('error', fn ($m) => str_contains($m, '6 each short'));

        $this->assertSame(30, $this->reserved($this->subLine($order)));
        $this->assertTrue($order->fresh()->oversold);
    }

    public function test_undo_puts_the_original_back_and_removes_the_substitute()
    {
        $order = $this->teaOrder();
        $tea = $order->lines()->sole();
        $this->substitute($order, $tea, ['packs' => 2], ['each' => 12]);
        $s = OrderSubstitution::sole();
        $subLineId = $s->substitute_line_id;

        $this->actingAs($this->manager)->post(route('orders.substitutions.undo', [$order, $s]))->assertSessionHasNoErrors()->assertSessionHas('success');

        $tea->refresh();
        $this->assertSame(0, $tea->qty_cancelled_base);
        $this->assertSame(0, $tea->qty_substituted_base);
        $this->assertSame(12, $this->reserved($tea));
        $this->assertNull(OrderLine::find($subLineId));
        $this->assertSame(0, (int) Reservation::where('product_id', $this->coffee->id)->sum('qty_base'));
        $s->refresh();
        $this->assertNotNull($s->undone_at);
        $this->assertSame($this->manager->id, $s->undone_by);
        $this->assertTrue($order->fresh()->oversold, 'short of tea again');
        $this->assertSame(1, AuditLog::where('event', 'order_substitution_undone')->count());

        $this->actingAs($this->manager)->post(route('orders.substitutions.undo', [$order, $s]))->assertSessionHasErrors('substitute');
    }

    public function test_undo_is_refused_once_the_substitute_is_on_a_pick_list()
    {
        $order = $this->teaOrder();
        $this->substitute($order, $order->lines()->sole(), ['packs' => 2], ['each' => 12]);
        app(PickListService::class)->create($this->picker, $this->site(), [$order->id]);

        $this->actingAs($this->manager)->get(route('orders.show', $order))
            ->assertInertia(fn (AssertableInertia $p) => $p->where('order.substitutions.0.can_undo', false)->where('order.lines.1.sub_label', 'SUB for TEA Tea'));
        $this->actingAs($this->manager)->post(route('orders.substitutions.undo', [$order, OrderSubstitution::sole()]))
            ->assertSessionHasErrors('substitute');
        $this->assertStringContainsString('is on pick list', session('errors')->first('substitute'));
    }

    public function test_a_short_pick_can_be_substituted_on_the_list_and_ships_without_a_backorder()
    {
        $this->stockIn($this->tea, 6);
        $order = $this->teaOrder(); // 12 tea: 6 to pick, 6 with no stock
        $list = app(PickListService::class)->create($this->picker, $this->site(), [$order->id])['pickList'];
        $tea = $order->lines()->sole();
        $noStock = $list->lines()->where('no_stock', true)->sole();
        $this->assertSame(6, $noStock->qty_to_pick_base);

        // The handheld offers it to a manager after the short is confirmed.
        $this->actingAs($this->manager)->get(route('pick.work', $list))->assertInertia(fn (AssertableInertia $p) => $p->where('canSubstitute', true));
        $this->actingAs($this->picker)->get(route('pick.work', $list))->assertInertia(fn (AssertableInertia $p) => $p->where('canSubstitute', false));
        $this->actingAs($this->manager)->put(route('pick.record', [$list, $noStock]), ['packs' => 0, 'each' => 0])->assertSessionHasNoErrors();

        $this->substitute($order, $tea, ['packs' => 1], ['each' => 6], ['pick_list_line_id' => $noStock->id])->assertSessionHasNoErrors();

        $this->assertNull($noStock->fresh(), 'the short is substituted, not short');
        $coffeeLine = $list->lines()->where('product_id', $this->coffee->id)->sole();
        $this->assertSame(6, $coffeeLine->qty_to_pick_base);
        $this->assertFalse($coffeeLine->no_stock);
        $this->assertSame($list->id, OrderSubstitution::sole()->pick_list_id);

        $this->actingAs($this->picker)->get(route('pick.work', $list))
            ->assertInertia(fn (AssertableInertia $p) => $p->where('lines', fn ($lines) => collect($lines)->firstWhere('product.sku', 'COF')['sub_label'] === 'SUB for TEA Tea'));

        $picks = app(PickListService::class);
        $picks->recordPicked($list, $list->lines()->pluck('qty_to_pick_base', 'id')->all());
        $picks->dispatch($list->fresh(), $this->picker);

        $order->refresh();
        $this->assertSame(OrderStatus::Dispatched, $order->status);
        $this->assertSame(6, $tea->fresh()->qty_dispatched_base);
        $this->assertSame(6, $this->subLine($order)->qty_dispatched_base);
        $this->assertSame(0, (int) Reservation::sum('qty_base'));
    }

    public function test_substituting_an_order_already_on_a_list_moves_the_unpicked_quantity_to_the_substitute()
    {
        $this->stockIn($this->tea, 12);
        $order = $this->teaOrder();
        $list = app(PickListService::class)->create($this->picker, $this->site(), [$order->id])['pickList'];

        $this->substitute($order, $order->lines()->sole(), ['packs' => 1], ['each' => 6])->assertSessionHasNoErrors();

        $this->assertSame(6, (int) $list->lines()->where('product_id', $this->tea->id)->sum('qty_to_pick_base'));
        $this->assertSame(6, (int) $list->lines()->where('product_id', $this->coffee->id)->sum('qty_to_pick_base'));
    }

    public function test_labels_show_on_the_pick_list_packing_slip_and_run_manifest()
    {
        $order = $this->teaOrder();
        $this->substitute($order, $order->lines()->sole(), ['packs' => 2], ['each' => 12]);
        $list = app(PickListService::class)->create($this->picker, $this->site(), [$order->id])['pickList'];

        $this->actingAs($this->picker)->get(route('pick-lists.show', $list))
            ->assertInertia(fn (AssertableInertia $p) => $p->where('pickList.lines.0.sub_label', 'SUB for TEA Tea'));
        $this->actingAs($this->picker)->get(route('pick.pack', $list))
            ->assertInertia(fn (AssertableInertia $p) => $p->where('orders.0.lines.0.sub_label', 'SUB for TEA Tea'));
        $this->actingAs($this->picker)->get(route('pick.manifest', $order->fresh()->deliveryRun))
            ->assertInertia(fn (AssertableInertia $p) => $p->where('orders.0.substitutions', ['COF Coffee: SUB for TEA Tea']));

        $this->mock(PdfRenderer::class)->shouldReceive('render')->once()
            ->withArgs(fn ($view, $data) => $view === 'pdf.packing-slip' && $data['rows'][0]['sub_label'] === 'SUB for TEA Tea')
            ->andReturn('%PDF-1.4');
        $this->actingAs($this->picker)->get(route('pick.slip', [$list, $order]))->assertOk();

        $html = view('pdf.packing-slip', ['order' => $order, 'rows' => [['sku' => 'COF', 'name' => 'Coffee', 'chilled' => false, 'pack' => 'Each', 'ordered' => '12', 'packed' => '12', 'short' => null, 'sub_label' => 'SUB for TEA Tea']],
            'company' => [], 'pack' => null, 'logoPath' => ''])->render();
        $this->assertStringContainsString('SUB for TEA Tea', $html);
    }

    public function test_an_ordermentum_resend_of_the_original_line_does_not_undo_the_substitution()
    {
        $order = $this->channelOrder(OrderChannel::Ordermentum, 'OM-1', 1, [[$this->tea, 12, 'om-line-1']]);
        $tea = $order->lines()->sole();
        $this->substitute($order, $tea, ['each' => 12], ['each' => 12])->assertSessionHasNoErrors();

        $this->channelOrder(OrderChannel::Ordermentum, 'OM-1', 2, [[$this->tea, 12, 'om-line-1']]);

        $tea->refresh();
        $this->assertSame(12, $tea->qty_cancelled_base);
        $this->assertSame(12, $tea->qty_substituted_base);
        $this->assertSame(0, $this->reserved($tea));
        $sub = $this->subLine($order);
        $this->assertSame(0, $sub->qty_cancelled_base, 'not cancelled as a line missing from the re-send');
        $this->assertSame(12, $this->reserved($sub));
        $this->assertArrayNotHasKey('substitution_reduced', $order->fresh()->review_notes ?? []);

        // Ordermentum then lowers the line below what was substituted: capped, and flagged.
        $this->channelOrder(OrderChannel::Ordermentum, 'OM-1', 3, [[$this->tea, 6, 'om-line-1']]);
        $this->assertSame(6, $tea->fresh()->qty_substituted_base);
        $this->assertSame(0, $tea->fresh()->outstandingBase());
        $this->assertArrayHasKey('substitution_reduced', $order->fresh()->review_notes);
    }

    public function test_ordermentum_substitutions_are_listed_on_sync_until_marked_done()
    {
        $order = $this->channelOrder(OrderChannel::Ordermentum, 'OM-1', 1, [[$this->tea, 12, 'om-line-1']]);
        $order->lines()->update(['unit_price' => '5']);
        $this->substitute($order, $order->lines()->sole(), ['each' => 12], ['each' => 12]);
        $manual = $this->teaOrder();
        $this->substitute($manual, $manual->lines()->sole(), ['packs' => 1], ['each' => 6]);
        $s = OrderSubstitution::where('order_id', $order->id)->sole();

        $this->actingAs($this->manager)->get(route('sync.index'))->assertInertia(fn (AssertableInertia $p) => $p
            ->has('substitutions', 1)
            ->where('substitutions.0.order.external_ref', 'OM-1')
            ->where('substitutions.0.original.sku', 'TEA')
            ->where('substitutions.0.substitute.sku', 'COF')
            ->where('substitutions.0.qty', '12 each')
            ->where('substitutions.0.price_difference', '-12.00'));

        $this->actingAs($this->manager)->put(route('sync.substitutions.update', $s), ['done' => true])->assertSessionHasNoErrors();
        $this->assertNotNull($s->fresh()->channel_done_at);
        $this->actingAs($this->manager)->get(route('sync.index'))->assertInertia(fn (AssertableInertia $p) => $p->has('substitutions', 0));

        // Undone after it was made in Ordermentum: back on the list, to be put back there.
        $this->actingAs($this->manager)->post(route('orders.substitutions.undo', [$order, $s]))->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->get(route('sync.index'))->assertInertia(fn (AssertableInertia $p) => $p->has('substitutions', 1)->where('substitutions.0.undone', true));
        $this->actingAs($this->manager)->put(route('sync.substitutions.update', $s), ['done' => true]);
        $this->assertNotNull($s->fresh()->channel_undo_done_at);
        $this->actingAs($this->manager)->get(route('sync.index'))->assertInertia(fn (AssertableInertia $p) => $p->has('substitutions', 0));
        $this->actingAs($this->userWithRole(Permissions::ROLE_READ_ONLY))->put(route('sync.substitutions.update', $s), ['done' => true])->assertForbidden();
    }

    public function test_a_portal_order_gets_a_substituted_event_with_both_lines_and_prices()
    {
        config(['bwms.portal' => array_merge(config('bwms.portal'), [
            'events_enabled' => true, 'base_url' => 'https://portal.test', 'outbound_key_id' => 'bwms', 'outbound_secret' => 'out-secret',
        ])]);
        $this->app->forgetInstance(PortalClient::class);
        ChannelListing::create(['channel' => 'portal', 'product_id' => $this->coffee->id, 'product_unit_id' => $this->unitId($this->coffee, 6), 'external_product_id' => '702', 'external_variant_id' => '702']);
        $sent = [];
        Http::fake(['portal.test/*' => function (Request $r) use (&$sent) {
            $sent[] = $r;

            return Http::response(['ok' => true]);
        }]);
        $this->stockIn($this->tea, 12);
        $order = $this->channelOrder(OrderChannel::Portal, 'ORD-A', 1, [[$this->tea, 12, '501']]);
        $order->lines()->update(['unit_price' => '5']);

        $this->substitute($order, $order->lines()->sole(), ['each' => 12], ['packs' => 2], ['product_unit_id' => $this->unitId($this->coffee, 6)])->assertSessionHasNoErrors();

        $this->assertCount(1, $sent);
        $s = OrderSubstitution::sole();
        $this->assertSame([
            'order_ref' => 'ORD-A',
            'event' => 'substituted',
            'substitution_id' => $s->id,
            'original' => ['line_ref' => '501', 'qty' => 12, 'unit_price' => '5.0000'],
            'substitute' => ['line_ref' => "bwms-sub-{$s->id}", 'portal_product_id' => 702, 'sku' => 'COF', 'name' => 'Coffee', 'unit' => 'Carton', 'unit_quantity' => 6, 'qty' => 2, 'unit_price' => '24.0000'],
            'price_difference' => '-12.00',
            'at' => '2026-11-13T23:00:00Z',
        ], $sent[0]->data());
        $this->assertSame("bwms:order-event:ORD-A:substituted:{$s->id}", $sent[0]->header('Idempotency-Key')[0]);

        $this->actingAs($this->manager)->post(route('orders.substitutions.undo', [$order, $s]))->assertSessionHasNoErrors();
        $this->assertCount(2, $sent);
        $this->assertSame('substitution_undone', $sent[1]->data()['event']);
        $this->assertSame("bwms:order-event:ORD-A:substitution_undone:{$s->id}", $sent[1]->header('Idempotency-Key')[0]);
    }

    public function test_the_margin_report_costs_the_substitute_and_the_substitutions_report_lists_it()
    {
        $this->tea->forceFill(['avg_cost' => 3])->save();
        $this->coffee->forceFill(['avg_cost' => 2])->save();
        $order = $this->teaOrder();
        $this->substitute($order, $order->lines()->sole(), ['packs' => 2], ['each' => 12]);
        $list = app(PickListService::class)->create($this->picker, $this->site(), [$order->id])['pickList'];
        app(PickListService::class)->recordPicked($list, $list->lines()->pluck('qty_to_pick_base', 'id')->all());
        app(PickListService::class)->dispatch($list, $this->picker);
        $date = $order->fresh()->delivery_date->toDateString();

        $row = app(DropReport::class)->real($date, $date, 0, 0)['orders'][0];
        $this->assertSame(48.0, $row['sales']); // 12 coffee at $4
        $this->assertSame(24.0, $row['cost']);  // at the coffee's cost, not the tea's

        $this->actingAs($this->manager)->get(route('reports.substitutions.index', ['from' => '2026-11-14', 'to' => '2026-11-14']))
            ->assertInertia(fn (AssertableInertia $p) => $p->component('reports/Substitutions')
                ->has('report.rows', 1)
                ->where('report.rows.0.original_sku', 'TEA')->where('report.rows.0.substitute_sku', 'COF')
                ->where('report.rows.0.original_qty', '2 Carton')->where('report.rows.0.substitute_qty', '12 each')
                ->where('report.rows.0.price_difference', -12)->where('report.totals.count', 1));
        $this->actingAs($this->manager)->get(route('reports.substitutions.index', ['from' => '2026-11-15', 'to' => '2026-11-20']))
            ->assertInertia(fn (AssertableInertia $p) => $p->has('report.rows', 0));

        $csv = $this->actingAs($this->manager)->get(route('reports.substitutions.export', ['format' => 'csv', 'from' => '2026-11-14', 'to' => '2026-11-14']));
        $csv->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="substitutions-2026-11-14-to-2026-11-14.csv"');
        $this->assertStringContainsString('Accepted by Jane by phone 14/11', $csv->getContent());
        $this->actingAs($this->manager)->get(route('reports.substitutions.export', ['format' => 'xlsx']))->assertOk();
        $this->actingAs($this->picker)->get(route('reports.substitutions.index'))->assertForbidden();
    }
}
