<?php

namespace Tests\Feature\Catalogue;

use App\Authorization\Permissions;
use App\Models\Product;
use App\Services\Catalogue\SellPriceCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** The sell price is per sale pack; one below what that pack costs was probably entered per unit. */
class SellPriceCheckTest extends TestCase
{
    use RefreshDatabase;

    /** Sold by the Carton of 24 (the default sale pack), costing $2 a can, so $48 a carton. */
    private function carton(string $sku, ?string $sell, string $cost = '2', array $extra = []): Product
    {
        $p = Product::factory()->withPack('Carton', 24)->create(['sku' => $sku, 'base_unit' => 'can', 'sell_price' => $sell] + $extra);
        $p->forceFill(['avg_cost' => $cost])->save();
        $p->units()->update(['default_sale' => false]);
        $p->units()->where('base_qty', 24)->update(['default_sale' => true]);

        return $p;
    }

    public function test_lists_products_selling_below_cost_per_pack_with_the_likely_fix()
    {
        $this->carton('PER-UNIT', '3.20');           // $3.20 a can typed in: under the $48 a carton costs
        $this->carton('RIGHT', '76.80');             // per carton: fine
        $this->carton('NO-COST', '3.20', '0');       // no cost yet: can't tell
        $this->carton('INACTIVE', '3.20', extra: ['is_active' => false]);
        $this->carton('NO-PRICE', null);

        $rows = app(SellPriceCheck::class)->belowCost();

        $this->assertSame(['PER-UNIT'], array_column($rows, 'sku'));
        $this->assertSame(['pack' => 'Carton', 'pack_qty' => 24, 'sell_price' => 3.2, 'cost_per_pack' => 48.0, 'sell_per_unit' => 0.1333, 'price_if_per_unit' => 76.8],
            array_intersect_key($rows[0], array_flip(['pack', 'pack_qty', 'sell_price', 'cost_per_pack', 'sell_per_unit', 'price_if_per_unit'])));
    }

    public function test_without_a_default_sale_pack_the_largest_sellable_pack_is_used()
    {
        $p = $this->carton('NO-DEFAULT', '40');
        $p->units()->update(['default_sale' => false]); // falls back to the Carton of 24: $40 < $48

        $this->assertSame(['NO-DEFAULT'], array_column(app(SellPriceCheck::class)->belowCost(), 'sku'));
    }

    public function test_the_product_list_shows_the_count_and_filters_to_them_for_those_who_see_costs()
    {
        $this->carton('PER-UNIT', '3.20');
        $this->carton('RIGHT', '76.80');
        $manager = $this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER);

        $this->actingAs($manager)->get(route('products.index'))->assertInertia(fn (AssertableInertia $p) => $p
            ->where('priceCheck.count', 1)->where('priceCheck.rows', [])->has('products.data', 2));
        $this->actingAs($manager)->get(route('products.index', ['check' => 'below_cost']))->assertInertia(fn (AssertableInertia $p) => $p
            ->where('priceCheck.rows.0.sku', 'PER-UNIT')->has('products.data', 1)->where('products.data.0.sku', 'PER-UNIT'));

        // Warehouse staff don't see costs, so they get no check, and the filter does nothing.
        $this->actingAs($this->userWithRole(Permissions::ROLE_WAREHOUSE_STAFF))->get(route('products.index', ['check' => 'below_cost']))
            ->assertInertia(fn (AssertableInertia $p) => $p->where('priceCheck', null)->has('products.data', 2));
    }
}
