<?php

namespace Tests\Feature\Catalogue;

use App\Authorization\Permissions;
use App\Enums\OrderStatus;
use App\Models\ChannelListing;
use App\Models\Product;
use App\Models\User;
use App\Services\Orders\AvailabilityService;
use App\Services\Orders\PickListService;
use App\Services\Orders\ReservationService;
use App\Services\Stock\Exceptions\InvalidMovement;
use App\Services\Stock\StocktakeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Support\OrderFixtures;
use Tests\Support\StockFixtures;
use Tests\TestCase;

class ProductFlagsTest extends TestCase
{
    use OrderFixtures, RefreshDatabase, StockFixtures;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedStockReferenceData();
        $this->manager = $this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER);
    }

    private function service(): Product
    {
        return Product::factory()->create(['sku' => 'CHAIDWNP', 'is_stock_tracked' => false, 'sell_channels' => 'none']);
    }

    public function test_a_non_stock_tracked_product_has_no_movements_and_is_not_counted()
    {
        $service = $this->service();
        $goods = Product::factory()->create();
        $this->stockIn($goods, 5);

        try {
            $this->stockIn($service, 1);
            $this->fail('Expected InvalidMovement');
        } catch (InvalidMovement $e) {
            $this->assertStringContainsString('not stock-tracked', $e->getMessage());
        }

        $stocktakes = app(StocktakeService::class);
        $stocktake = $stocktakes->create($this->manager, $this->site());
        $this->assertSame([$goods->id], $stocktake->lines()->pluck('product_id')->all());
        $this->expectException(InvalidMovement::class);
        $stocktakes->addLine($stocktake, $service, $this->bin(), null);
    }

    public function test_service_lines_reserve_nothing_are_not_picked_and_complete_at_dispatch()
    {
        $service = $this->service();
        $goods = Product::factory()->create();
        $this->stockIn($goods, 10);
        $order = $this->manualOrder($this->manager, $goods, 2);
        $order->lines()->create(['line_ref' => 'S', 'product_id' => $service->id, 'product_unit_id' => $service->units()->value('id'),
            'qty' => 1, 'base_qty_per_unit' => 1, 'qty_base' => 1, 'sort_order' => 1]);
        app(ReservationService::class)->syncOrder($order->fresh(), false);

        $this->assertSame(0, (int) $order->lines()->where('product_id', $service->id)->first()->reservation?->qty_base);
        $this->assertSame(8, app(AvailabilityService::class)->available($goods));

        $list = app(PickListService::class)->create($this->manager, $this->site(), [$order->id])['pickList'];
        $this->assertSame([$goods->id], $list->lines()->pluck('product_id')->all(), 'Services are not picked');

        $this->actingAs($this->manager)->put(route('pick-lists.record', $list), ['picked' => [$list->lines()->value('id') => 2]]);
        $this->actingAs($this->manager)->post(route('pick-lists.dispatch', $list))->assertSessionHasNoErrors();

        $this->assertSame(OrderStatus::Dispatched, $order->fresh()->status);
        $this->assertSame(1, $order->lines()->where('product_id', $service->id)->value('qty_dispatched_base'));
    }

    public function test_pushes_only_flag_stock_tracked_products_sold_on_that_channel()
    {
        $make = function (array $attrs, string $variant) {
            $p = Product::factory()->create($attrs);
            ChannelListing::create(['channel' => 'ordermentum', 'product_id' => $p->id, 'product_unit_id' => $p->units()->value('id'),
                'external_variant_id' => $variant, 'sync_enabled' => true]);

            return $p;
        };
        $sold = $make(['sell_channels' => 'both'], 'v1');
        $internal = $make(['sell_channels' => 'none'], 'v2');
        $portalOnly = $make(['sell_channels' => 'portal'], 'v3');

        $flagged = ChannelListing::requestPush('ordermentum', [$sold->id, $internal->id, $portalOnly->id]);

        $this->assertSame(1, $flagged);
        $this->assertNotNull(ChannelListing::where('product_id', $sold->id)->value('push_requested_at'));
    }

    public function test_unmapped_warning_ignores_products_with_no_sell_channel()
    {
        Product::factory()->create(['sku' => 'CN440X320X160', 'sell_channels' => 'none']);
        Product::factory()->create(['sku' => 'NEW-OM-1', 'sell_channels' => 'ordermentum']);

        $this->actingAs($this->manager)->get(route('sync.index'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('unmappedProducts.total', 1)->where('unmappedProducts.rows.0.sku', 'NEW-OM-1'));
    }

    public function test_cannot_untick_stock_tracked_while_holding_stock()
    {
        $p = Product::factory()->create();
        $this->stockIn($p, 3);
        $admin = $this->userWithRole(Permissions::ROLE_ADMIN);
        $unit = $p->units()->first();

        $this->actingAs($admin)->put(route('products.update', $p), [
            'sku' => $p->sku, 'name' => $p->name, 'base_unit' => $p->base_unit, 'is_stock_tracked' => false, 'sell_channels' => 'none',
            'units' => [['id' => $unit->id, 'name' => $unit->name, 'base_qty' => 1, 'purchasable' => true, 'sellable' => true]],
        ])->assertSessionHasErrors('is_stock_tracked');

        $this->assertTrue($p->fresh()->is_stock_tracked);
    }

    public function test_mapping_a_portal_product_adds_portal_to_sell_channels()
    {
        $p = Product::factory()->create(['sell_channels' => 'ordermentum']);
        $p->addSellChannel('portal');
        $this->assertSame('both', $p->fresh()->sell_channels);

        $q = Product::factory()->create(['sell_channels' => 'none']);
        $q->addSellChannel('portal');
        $this->assertSame('portal', $q->fresh()->sell_channels);
    }
}
