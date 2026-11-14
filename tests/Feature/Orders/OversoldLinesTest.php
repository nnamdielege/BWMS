<?php

namespace Tests\Feature\Orders;

use App\Authorization\Permissions;
use App\Enums\OrderChannel;
use App\Jobs\SendPortalCancelEvent;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Product;
use App\Models\User;
use App\Services\Orders\ChannelOrderData;
use App\Services\Orders\OrderService;
use App\Services\Orders\PickListService;
use Database\Seeders\DeliveryAreaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Inertia\Testing\AssertableInertia;
use Tests\Support\StockFixtures;
use Tests\TestCase;

/** The oversold flag says which lines are short, and by how much. */
class OversoldLinesTest extends TestCase
{
    use RefreshDatabase, StockFixtures;

    private User $manager;

    private Product $alm;

    private Product $oat;

    private Product $tea;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-12 09:00', 'Australia/Brisbane'));
        $this->seedStockReferenceData();
        $this->seed(DeliveryAreaSeeder::class);
        $this->manager = $this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER);
        $this->alm = Product::factory()->withPack('Case', 6)->create(['sku' => 'MLKALM', 'name' => 'Almond milk']);
        $this->oat = Product::factory()->create(['sku' => 'MLKOAT', 'name' => 'Oat milk']);
        $this->tea = Product::factory()->create(['sku' => 'TEA', 'name' => 'Tea']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param  list<array{0: Product, 1: int, 2?: int}>  $lines  [product, qty, pack size] */
    private function order(array $lines): Order
    {
        $customer = Customer::factory()->create(['delivery_postcode' => '4019']);

        return app(OrderService::class)->createManual($this->manager, [
            'customer_id' => $customer->id, 'site_id' => $this->site()->id, 'short_ship_policy' => 'backorder',
            'lines' => array_map(fn ($l) => ['product_id' => $l[0]->id, 'product_unit_id' => $l[0]->units()->where('base_qty', $l[2] ?? 1)->value('id'), 'qty' => $l[1]], $lines),
        ], allowBackorder: true);
    }

    private function line(Order $order, Product $p): OrderLine
    {
        return $order->lines()->where('product_id', $p->id)->sole();
    }

    private function lineCleared(Order $order): int
    {
        return AuditLog::where('event', 'order_line_oversold_cleared')->where('auditable_id', $order->id)->count();
    }

    public function test_a_single_oversold_line_shows_how_short_it_is()
    {
        $this->stockIn($this->alm, 8);
        $order = $this->order([[$this->alm, 2, 6]]); // 12 ordered, 8 in stock

        $this->assertTrue($order->oversold);
        $this->assertSame(4, $this->line($order, $this->alm)->oversold_base);
        $this->actingAs($this->manager)->get(route('orders.show', $order))->assertInertia(fn (AssertableInertia $p) => $p
            ->where('order.lines.0.oversold', 4)
            ->where('order.lines.0.oversold_label', '4 each')
            ->where('can.view_stock', true));
    }

    public function test_only_the_short_lines_are_flagged_and_listed_on_every_screen()
    {
        $this->stockIn($this->alm, 6);
        $this->stockIn($this->tea, 10);
        $order = $this->order([[$this->alm, 2, 6], [$this->oat, 5], [$this->tea, 2]]);

        $this->assertSame([6, 5, 0], [$this->line($order, $this->alm)->oversold_base, $this->line($order, $this->oat)->oversold_base, $this->line($order, $this->tea)->oversold_base]);
        $this->actingAs($this->manager)->get(route('orders.show', $order))->assertInertia(fn (AssertableInertia $p) => $p
            ->where('order.lines.0.oversold_label', '1 Case')->where('order.lines.2.oversold', 0));
        $this->actingAs($this->manager)->get(route('orders.index'))->assertInertia(fn (AssertableInertia $p) => $p
            ->where('orders.data.0.oversold_skus', ['MLKALM', 'MLKOAT']));
        $this->actingAs($this->manager)->get(route('pick.queue'))->assertInertia(fn (AssertableInertia $p) => $p
            ->where('runs', fn ($runs) => collect($runs)->flatMap(fn ($r) => $r['orders'])->firstWhere('id', $order->id)['oversold_skus'] === ['MLKALM', 'MLKOAT']));

        $list = app(PickListService::class)->create($this->manager, $this->site(), [$order->id])['pickList'];
        $this->actingAs($this->manager)->get(route('pick-lists.index'))->assertInertia(fn (AssertableInertia $p) => $p
            ->where('open.0.id', $list->id)->where('open.0.oversold_skus', ['MLKALM', 'MLKOAT']));
    }

    public function test_partial_cover_shrinks_the_line_and_full_cover_clears_it_then_the_order()
    {
        $order = $this->order([[$this->alm, 6], [$this->oat, 5]]);

        $this->stockIn($this->alm, 2);
        $this->assertSame(4, $this->line($order, $this->alm)->oversold_base);
        $this->assertSame(0, $this->lineCleared($order));

        $this->stockIn($this->alm, 4); // receive
        $this->assertSame(0, $this->line($order, $this->alm)->oversold_base);
        $entry = AuditLog::where('event', 'order_line_oversold_cleared')->sole();
        $this->assertSame('MLKALM', $entry->meta['sku']);
        $this->assertSame(['oversold_base' => 4], $entry->old_values); // what was left short before this receipt
        $this->assertTrue($order->fresh()->oversold, 'oat is still short, so the order stays flagged');

        $this->stockIn($this->oat, 5);
        $this->assertSame(0, $this->line($order, $this->oat)->oversold_base);
        $this->assertSame(2, $this->lineCleared($order));
        $this->assertFalse($order->fresh()->oversold);
    }

    public function test_the_shortfall_falls_on_the_newest_order_first()
    {
        $this->stockIn($this->oat, 6);
        $older = $this->order([[$this->oat, 6]]);
        $newer = $this->order([[$this->oat, 4]]);

        $this->assertSame(0, $this->line($older, $this->oat)->oversold_base);
        $this->assertSame(4, $this->line($newer, $this->oat)->oversold_base);

        $this->stockIn($this->oat, 1);
        $this->assertSame(3, $this->line($newer, $this->oat)->oversold_base);
    }

    public function test_substituting_the_short_line_clears_it_and_the_order()
    {
        $coffee = Product::factory()->create(['sku' => 'COF']);
        $this->stockIn($coffee, 10);
        $this->stockIn($this->tea, 2);
        $order = $this->order([[$this->oat, 5], [$this->tea, 2]]);
        $oat = $this->line($order, $this->oat);

        $this->actingAs($this->manager)->post(route('orders.substitute', [$order, $oat]), [
            'replace' => ['each' => 5], 'product_id' => $coffee->id, 'product_unit_id' => $coffee->units()->value('id'), 'qty' => ['each' => 5], 'note' => 'ok by phone',
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, $oat->fresh()->oversold_base);
        $this->assertSame(1, $this->lineCleared($order));
        $this->assertFalse($order->fresh()->oversold);
    }

    public function test_cancelling_the_short_qty_of_a_line_clears_it()
    {
        $this->stockIn($this->oat, 2);
        $order = $this->order([[$this->oat, 5]]);
        $oat = $this->line($order, $this->oat);
        $this->assertSame(3, $oat->oversold_base);

        $this->actingAs($this->userWithRole(Permissions::ROLE_WAREHOUSE_STAFF))
            ->post(route('orders.lines.cancel-qty', [$order, $oat]), ['each' => 3, 'reason' => 'Customer declined'])->assertForbidden();
        $this->actingAs($this->manager)->post(route('orders.lines.cancel-qty', [$order, $oat]), ['each' => 9, 'reason' => 'x'])->assertSessionHasErrors('line');
        $this->actingAs($this->manager)->post(route('orders.lines.cancel-qty', [$order, $oat]), ['each' => 3, 'reason' => 'Customer declined'])->assertSessionHasNoErrors();

        $oat->refresh();
        $this->assertSame(3, $oat->qty_cancelled_base);
        $this->assertSame(2, $oat->reservation->qty_base);
        $this->assertSame(0, $oat->oversold_base);
        $this->assertFalse($order->fresh()->oversold);
        $this->assertSame('Customer declined', AuditLog::where('event', 'order_line_qty_cancelled')->sole()->meta['reason']);
    }

    public function test_the_recheck_command_fills_in_lines_on_orders_flagged_before_lines_were()
    {
        $order = $this->order([[$this->alm, 6], [$this->tea, 1]]);
        OrderLine::query()->update(['oversold_base' => 0]); // as migrated

        $this->artisan('orders:recheck-oversold')->assertSuccessful();

        $this->assertSame(6, $this->line($order, $this->alm)->oversold_base);
        $this->assertSame(1, $this->line($order, $this->tea)->oversold_base);
        $this->assertTrue($order->fresh()->oversold);
    }

    public function test_marking_flags_handled_clears_the_lines_too_and_a_cancelled_order_shows_none()
    {
        $order = $this->order([[$this->oat, 5]]);
        $this->actingAs($this->manager)->post(route('orders.clear-flags', $order));
        $this->assertSame(0, $this->line($order, $this->oat)->oversold_base);

        $other = $this->order([[$this->oat, 5]]);
        $this->actingAs($this->manager)->post(route('orders.cancel', $other), ['reason' => 'gone']);
        $this->actingAs($this->manager)->get(route('orders.show', $other))->assertInertia(fn (AssertableInertia $p) => $p->where('order.lines.0.oversold', 0));
        $this->assertSame(0, $this->line($other, $this->oat)->oversold_base, 'cleared by the re-check once the order closed');
    }

    public function test_a_line_cancel_on_a_portal_order_tells_the_portal_under_its_own_key()
    {
        Bus::fake([SendPortalCancelEvent::class]);
        config(['bwms.portal.events_enabled' => true]);
        $this->stockIn($this->oat, 10);
        $order = app(OrderService::class)->upsertFromChannel(new ChannelOrderData(
            channel: OrderChannel::Portal, externalRef: 'ORD-A', version: 1, status: 'confirmed', siteId: $this->site()->id,
            lines: [['line_ref' => '501', 'product_id' => $this->oat->id, 'product_unit_id' => $this->oat->units()->value('id'), 'qty' => 5]],
            requestedDeliveryDate: null, delivery: [],
        ));

        $this->actingAs($this->manager)->post(route('orders.lines.cancel-qty', [$order, $order->lines()->sole()]), ['each' => 2, 'reason' => 'Customer declined'])->assertSessionHasNoErrors();

        Bus::assertDispatched(SendPortalCancelEvent::class, fn ($job) => $job->lines === [['line_ref' => '501', 'qty' => 2]]
            && str_starts_with((string) $job->scope, 'line-') && $job->reason === 'Customer declined');
    }
}
