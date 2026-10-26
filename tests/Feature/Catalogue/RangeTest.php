<?php

namespace Tests\Feature\Catalogue;

use App\Authorization\Permissions;
use App\Models\Product;
use App\Models\Range;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_flavours_are_separate_products_grouped_by_an_optional_range()
    {
        $manager = $this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER);
        $this->actingAs($manager)->post(route('ranges.store'), ['name' => 'Dash syrups'])->assertSessionHasNoErrors();
        $range = Range::where('name', 'Dash syrups')->sole();

        foreach (['DASH-VAN' => 'Dash Vanilla 750mL', 'DASH-CAR' => 'Dash Caramel 750mL'] as $sku => $name) {
            $this->actingAs($manager)->post(route('products.store'), [
                'sku' => $sku, 'name' => $name, 'base_unit' => 'bottle', 'range_id' => $range->id,
                'units' => [['name' => 'Bottle', 'base_qty' => 1]],
            ])->assertSessionHasNoErrors();
        }

        $this->assertSame(2, $range->products()->count());
        $this->assertSame(2, Product::whereIn('sku', ['DASH-VAN', 'DASH-CAR'])->distinct('id')->count());

        $this->actingAs($manager)->get(route('products.index', ['range_id' => $range->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('products.data', 2)->where('products.data.0.range.name', 'Dash syrups'));
    }

    public function test_range_is_optional_and_names_are_unique()
    {
        $manager = $this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER);
        Range::create(['name' => 'Kombucha']);

        $this->actingAs($manager)->post(route('ranges.store'), ['name' => 'Kombucha'])->assertSessionHasErrors('name');
        $this->actingAs($manager)->post(route('products.store'), [
            'sku' => 'SOLO', 'name' => 'Solo', 'base_unit' => 'each', 'units' => [['name' => 'Each', 'base_qty' => 1]],
        ])->assertSessionHasNoErrors();
        $this->assertNull(Product::where('sku', 'SOLO')->sole()->range_id);
    }

    public function test_read_only_cannot_create_ranges()
    {
        $this->actingAs($this->userWithRole(Permissions::ROLE_READ_ONLY))
            ->post(route('ranges.store'), ['name' => 'X'])->assertForbidden();
    }
}
