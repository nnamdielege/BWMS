<?php

namespace Tests\Feature\Catalogue;

use App\Authorization\Permissions;
use App\Models\Product;
use App\Rules\Gtin;
use Database\Factories\ProductFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'sku' => 'kmb-gng-330',
            'name' => 'Kombucha Ginger 330ml',
            'base_unit' => 'bottle',
            'is_batch_tracked' => true,
            'shelf_life_days' => 90,
            'is_active' => true,
            'units' => [
                ['name' => 'Bottle', 'base_qty' => 1, 'default_sale' => true, 'barcodes' => ['9300633603434']],
                ['name' => 'Carton', 'base_qty' => 12, 'default_purchase' => true, 'barcodes' => ['19300633603431']],
            ],
        ], $overrides);
    }

    public function test_manager_can_create_product_with_pack_sizes_and_barcodes()
    {
        $this->actingAs($this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER))
            ->post(route('products.store'), $this->payload())
            ->assertSessionHasNoErrors();

        $product = Product::where('sku', 'KMB-GNG-330')->firstOrFail(); // SKU normalised to upper case
        $this->assertTrue($product->is_batch_tracked);
        $this->assertSame([1, 12], $product->units->pluck('base_qty')->all());
        $this->assertTrue($product->units->firstWhere('base_qty', 1)->is_base);
        $this->assertSame(['9300633603434'], $product->units->firstWhere('base_qty', 1)->barcodes->pluck('barcode')->all());
        $this->assertDatabaseHas('audit_logs', ['event' => 'created', 'auditable_type' => Product::class, 'auditable_id' => $product->id]);
    }

    public function test_read_only_user_can_view_but_not_create()
    {
        $user = $this->userWithRole(Permissions::ROLE_READ_ONLY);

        $this->actingAs($user)->get(route('products.index'))->assertOk();
        $this->actingAs($user)->post(route('products.store'), $this->payload())->assertForbidden();
        $this->assertSame(0, Product::count());
    }

    public function test_exactly_one_base_unit_is_required()
    {
        $manager = $this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER);

        $this->actingAs($manager)
            ->post(route('products.store'), $this->payload(['units' => [['name' => 'Carton', 'base_qty' => 12]]]))
            ->assertSessionHasErrors('units');
    }

    public function test_barcode_must_be_unique_across_products_and_valid_gtin()
    {
        $manager = $this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER);
        $this->actingAs($manager)->post(route('products.store'), $this->payload())->assertSessionHasNoErrors();

        $this->actingAs($manager)->post(route('products.store'), $this->payload([
            'sku' => 'OTHER',
            'units' => [['name' => 'Each', 'base_qty' => 1, 'barcodes' => ['9300633603434']]],
        ]))->assertSessionHasErrors('units');

        $this->actingAs($manager)->post(route('products.store'), $this->payload([
            'sku' => 'BADCHECK',
            'units' => [['name' => 'Each', 'base_qty' => 1, 'barcodes' => ['9300633603437']]],
        ]))->assertSessionHasErrors('units.0.barcodes.0');
    }

    public function test_saved_pack_quantity_is_immutable()
    {
        $manager = $this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER);
        $product = Product::factory()->withPack('Carton', 12)->create();
        $carton = ProductFactory::unitOf($product, 12);
        $base = ProductFactory::unitOf($product, 1);

        $this->actingAs($manager)->put(route('products.update', $product), $this->payload([
            'sku' => $product->sku,
            'units' => [
                ['id' => $base->id, 'name' => 'Each', 'base_qty' => 1],
                ['id' => $carton->id, 'name' => 'Carton', 'base_qty' => 24],
            ],
        ]))->assertSessionHasErrors('units');

        $this->assertSame(12, $carton->fresh()->base_qty);
    }

    public function test_pack_size_can_be_removed_and_added()
    {
        $manager = $this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER);
        $product = Product::factory()->withPack('Carton', 12)->create();
        $base = ProductFactory::unitOf($product, 1);

        $this->actingAs($manager)->put(route('products.update', $product), $this->payload([
            'sku' => $product->sku,
            'units' => [
                ['id' => $base->id, 'name' => 'Each', 'base_qty' => 1],
                ['name' => 'Half carton', 'base_qty' => 6],
            ],
        ]))->assertSessionHasNoErrors();

        $this->assertSame([1, 6], $product->fresh()->units->pluck('base_qty')->all());
    }

    public function test_barcode_lookup_resolves_product_and_pack()
    {
        $manager = $this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER);
        $this->actingAs($manager)->post(route('products.store'), $this->payload());

        $this->actingAs($manager)->getJson(route('barcodes.lookup', '19300633603431'))
            ->assertOk()
            ->assertJsonPath('product.sku', 'KMB-GNG-330')
            ->assertJsonPath('unit.base_qty', 12);

        $this->actingAs($manager)->getJson(route('barcodes.lookup', '0000'))->assertNotFound();
    }

    public function test_unit_conversion_helpers()
    {
        $product = Product::factory()->withPack('Carton', 12)->create();
        $carton = ProductFactory::unitOf($product, 12);

        $this->assertSame(36, $carton->toBase(3));
        $this->assertSame(2, $carton->wholeUnitsFromBase(35));
        $this->assertSame(0, $carton->wholeUnitsFromBase(-5));
    }

    public function test_gtin_check_digit()
    {
        $this->assertTrue(Gtin::hasValidCheckDigit('9300633603434'));
        $this->assertTrue(Gtin::hasValidCheckDigit('19300633603431'));
        $this->assertTrue(Gtin::hasValidCheckDigit('96385074'));
        $this->assertFalse(Gtin::hasValidCheckDigit('9300633603437'));
    }
}
