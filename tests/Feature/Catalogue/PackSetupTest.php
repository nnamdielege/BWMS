<?php

namespace Tests\Feature\Catalogue;

use App\Authorization\Permissions;
use App\Models\ChannelListing;
use App\Models\Product;
use App\Models\PurchaseOrderLine;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Catalogue\PackSetupService;
use App\Services\Purchasing\PurchaseOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia;
use Tests\Support\StockFixtures;
use Tests\TestCase;

class PackSetupTest extends TestCase
{
    use RefreshDatabase, StockFixtures;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedStockReferenceData();
        $this->manager = $this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER);
    }

    /** A product as the legacy migration leaves it: one "Unit" pack (= the Ordermentum sell unit). */
    private function migratedProduct(string $sku = 'DASH-VAN'): Product
    {
        $p = Product::factory()->create(['sku' => $sku, 'base_unit' => 'unit', 'reorder_point' => 2, 'reorder_qty' => 5]);
        $p->forceFill(['avg_cost' => '12.0000', 'legacy_id' => $p->id])->save();
        $p->units()->update(['name' => 'Unit']);

        return $p->fresh('units');
    }

    private function upload(array $lines)
    {
        $csv = UploadedFile::fake()->createWithContent('packs.csv', implode("\n", $lines)."\n");

        return $this->actingAs($this->manager)->post(route('product-packs.preview'), ['file' => $csv]);
    }

    private function applyPreview(): void
    {
        $token = session('pack_preview')['token'];
        $this->actingAs($this->manager)->post(route('product-packs.apply'), ['token' => $token])->assertSessionHasNoErrors();
    }

    public function test_legacy_unit_becomes_a_carton_and_everything_recorded_in_it_moves_with_it()
    {
        $p = $this->migratedProduct();
        $unit = $p->units->first();
        $unit->barcodes()->create(['barcode' => '9300000000019', 'type' => 'ean13']);
        $listing = ChannelListing::create(['channel' => 'ordermentum', 'product_id' => $p->id, 'product_unit_id' => $unit->id, 'external_variant_id' => 'v-1']);
        $po = app(PurchaseOrderService::class)->saveDraft(null, [
            'supplier_id' => Supplier::factory()->create()->id, 'site_id' => $this->site()->id, 'order_date' => '2026-01-01',
            'lines' => [['product_id' => $p->id, 'product_unit_id' => $unit->id, 'qty_ordered' => 5, 'unit_cost' => '12.00']],
        ], $this->manager);
        $po->forceFill(['legacy_id' => 99])->save();

        $this->upload(['sku,base_unit,pack_name,qty_per_pack,legacy_unit', 'DASH-VAN,bottle,Bottle,1,', 'DASH-VAN,bottle,Carton,12,yes'])
            ->assertSessionHas('pack_preview', fn ($p) => $p['errors'] === [] && $p['plans'][0]['factor'] === 12 && $p['plans'][0]['po_lines'] === 1);
        $this->assertSame('unit', $p->fresh()->base_unit, 'Preview changes nothing');
        $this->applyPreview();

        $p = $p->fresh('units');
        $carton = $p->units->firstWhere('base_qty', 12);
        $this->assertSame(['bottle', 'Bottle', 'Carton'], [$p->base_unit, $p->units->firstWhere('base_qty', 1)->name, $carton->name]);
        $this->assertSame(['1.0000', 24, 60], [(string) $p->avg_cost, $p->reorder_point, $p->reorder_qty]);
        $this->assertSame($carton->id, $listing->fresh()->product_unit_id);
        $this->assertSame(['9300000000019'], $carton->barcodes->pluck('barcode')->all());
        $line = PurchaseOrderLine::sole();
        $this->assertSame([$carton->id, 5, 12, 60], [$line->product_unit_id, $line->qty_ordered, $line->base_qty_per_unit, $line->qty_ordered_base]);
        $this->assertTrue($carton->default_sale && $carton->default_purchase);
        $this->assertDatabaseHas('audit_logs', ['event' => 'pack_setup_applied', 'auditable_id' => $p->id]);
    }

    public function test_a_single_row_can_just_rename_the_base_unit()
    {
        $p = $this->migratedProduct();

        $this->upload(['sku,base_unit,pack_name,qty_per_pack', 'DASH-VAN,case,Case,1']);
        $this->applyPreview();

        $p = $p->fresh('units');
        $this->assertSame(['case', 'Case', '12.0000'], [$p->base_unit, $p->units->sole()->name, (string) $p->avg_cost]);
    }

    public function test_invalid_rows_and_products_with_stock_are_refused()
    {
        $this->migratedProduct('A-1');
        $stocked = $this->migratedProduct('B-1');
        $this->stockIn($stocked, 5);
        $this->migratedProduct('C-1');

        $this->upload([
            'sku,base_unit,pack_name,qty_per_pack,legacy_unit',
            'NOPE,each,Each,1,',
            'B-1,each,Each,1,',
            'A-1,each,Each,1,', 'A-1,each,Box,6,',            // two packs, none marked legacy
            'C-1,each,Each,1,', 'C-1,each,Box,1,yes',          // duplicate quantity
        ])->assertSessionHas('pack_preview', function ($preview) {
            $errors = implode("\n", $preview['errors']);

            return $preview['plans'] === []
                && str_contains($errors, 'NOPE (line 2): unknown SKU')
                && str_contains($errors, 'B-1 (line 3): already has stock movements')
                && str_contains($errors, 'A-1 (line 4, 5): mark exactly one row legacy_unit=yes')
                && str_contains($errors, 'C-1 (line 6, 7): each pack needs its own name and quantity');
        });
    }

    public function test_page_lists_default_pack_products_and_only_managers_apply_once()
    {
        $this->migratedProduct('A-1');
        $readOnly = $this->userWithRole(Permissions::ROLE_READ_ONLY);

        $this->actingAs($readOnly)->get(route('product-packs.index'))->assertInertia(fn (AssertableInertia $page) => $page
            ->component('products/Packs')->where('defaultCount', 1)->where('defaultProducts.0.sku', 'A-1'));
        $this->actingAs($readOnly)->get(route('product-packs.export'))->assertOk();
        $this->actingAs($readOnly)->post(route('product-packs.preview'))->assertForbidden();

        $this->upload(['sku,base_unit,pack_name,qty_per_pack', 'A-1,each,Each,1']);
        $token = session('pack_preview')['token'];
        $this->actingAs($this->manager)->post(route('product-packs.apply'), ['token' => $token]);
        $this->actingAs($this->manager)->post(route('product-packs.apply'), ['token' => $token])->assertSessionHas('error');
        $this->assertSame(0, PackSetupService::defaultPackProducts()->count());
    }
}
