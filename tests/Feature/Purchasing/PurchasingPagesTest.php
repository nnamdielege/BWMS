<?php

namespace Tests\Feature\Purchasing;

use App\Authorization\Permissions;
use App\Models\Product;
use App\Models\Supplier;
use App\Services\Purchasing\PurchaseOrderService;
use Database\Factories\ProductFactory;
use Database\Seeders\CompanySettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\StockFixtures;
use Tests\TestCase;

class PurchasingPagesTest extends TestCase
{
    use RefreshDatabase, StockFixtures;

    public function test_purchasing_pages_render()
    {
        $this->seedStockReferenceData();
        $this->seed(CompanySettingsSeeder::class);
        $manager = $this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER);
        $admin = $this->userWithRole(Permissions::ROLE_ADMIN);
        $supplier = Supplier::factory()->create();
        $product = Product::factory()->withPack('Carton', 12)->create();
        $po = app(PurchaseOrderService::class)->saveDraft(null, [
            'supplier_id' => $supplier->id, 'site_id' => $this->site()->id, 'order_date' => '2026-11-02',
            'lines' => [['product_id' => $product->id, 'product_unit_id' => ProductFactory::unitOf($product, 12)->id, 'qty_ordered' => 2, 'unit_cost' => '10']],
        ], $manager);

        foreach ([
            route('suppliers.index'), route('suppliers.create'), route('suppliers.edit', $supplier),
            route('purchase-orders.index'), route('purchase-orders.create'), route('purchase-orders.show', $po),
            route('purchase-orders.edit', $po), route('product-costs.index'),
        ] as $url) {
            $this->actingAs($manager)->get($url)->assertOk();
        }

        app(PurchaseOrderService::class)->markSent($po, $manager);
        $this->actingAs($manager)->get(route('purchase-orders.receive', $po))->assertOk();
        $this->actingAs($manager)->getJson(route('products.search', ['q' => $product->sku]))->assertOk()->assertJsonPath('0.sku', $product->sku);
        $this->actingAs($admin)->get(route('admin.settings.edit'))->assertOk();
    }
}
