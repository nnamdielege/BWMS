<?php

namespace Tests\Feature\Catalogue;

use App\Authorization\Permissions;
use App\Models\Product;
use Database\Factories\ProductFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class CostBackfillTest extends TestCase
{
    use RefreshDatabase;

    private function csv(string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('costs.csv', $content);
    }

    public function test_zero_cost_report_lists_only_zero_cost_products()
    {
        Product::factory()->create(['sku' => 'ZERO-1']);
        $costed = Product::factory()->create(['sku' => 'HAS-COST']);
        $costed->forceFill(['avg_cost' => 1.5])->save();

        $this->actingAs($this->userWithRole(Permissions::ROLE_READ_ONLY))
            ->get(route('product-costs.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('zeroCount', 1)->has('products.data', 1)->where('products.data.0.sku', 'ZERO-1'));
    }

    public function test_inline_cost_per_pack_is_stored_per_base_unit_and_audited()
    {
        $p = Product::factory()->withPack('Carton', 12)->create();
        $manager = $this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER);

        $this->actingAs($manager)->put(route('product-costs.update', $p), [
            'cost' => '30.00', 'product_unit_id' => ProductFactory::unitOf($p, 12)->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame('2.5000', (string) $p->fresh()->avg_cost);
        $this->assertDatabaseHas('audit_logs', ['event' => 'cost_set', 'auditable_id' => $p->id, 'user_id' => $manager->id]);
    }

    public function test_csv_preview_validates_without_changing_data_then_apply_updates_valid_rows_once()
    {
        $a = Product::factory()->withPack('Carton', 12)->create(['sku' => 'A1']);
        $b = Product::factory()->create(['sku' => 'B2']);
        $manager = $this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER);

        $csv = "SKU,Cost,Pack\na1,24,Carton\nB2,1.10,\nNOPE,5,\nB2,9,\nA1,abc,\n";
        $this->actingAs($manager)->post(route('product-costs.preview'), ['file' => $this->csv($csv)])
            ->assertSessionHas('cost_preview', function ($p) {
                return $p['valid'] === 2 && $p['errors'] === 3
                    && $p['rows'][0]['new_cost'] === '2.0000'
                    && $p['rows'][2]['error'] === 'Unknown SKU.'
                    && str_starts_with($p['rows'][3]['error'], 'Duplicate');
            });

        $this->assertSame('0.0000', (string) $a->fresh()->avg_cost, 'Preview must not change data');
        $token = session('cost_preview')['token'];

        $this->actingAs($manager)->post(route('product-costs.apply'), ['token' => $token])->assertSessionHas('success');
        $this->assertSame('2.0000', (string) $a->fresh()->avg_cost);
        $this->assertSame('1.1000', (string) $b->fresh()->avg_cost);

        // Token is single use.
        $this->actingAs($manager)->post(route('product-costs.apply'), ['token' => $token])->assertSessionHas('error');
    }

    public function test_csv_needs_sku_and_cost_headers()
    {
        $this->actingAs($this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER))
            ->post(route('product-costs.preview'), ['file' => $this->csv("code,price\nX,1\n")])
            ->assertSessionHas('cost_preview', fn ($p) => $p['valid'] === 0 && $p['errors'] === 1);
    }

    public function test_export_is_a_fillable_template_of_zero_cost_products()
    {
        Product::factory()->withPack('Carton', 6)->create(['sku' => 'TPL-1', 'name' => 'Template Product']);

        $response = $this->actingAs($this->userWithRole(Permissions::ROLE_READ_ONLY))->get(route('product-costs.export'));
        $content = $response->streamedContent();

        $this->assertStringStartsWith('sku,cost,pack,name,base_unit,available_packs', $content);
        $this->assertStringContainsString('TPL-1,,,"Template Product",each,"Each; Carton (6)"', $content);
    }

    public function test_read_only_cannot_change_costs()
    {
        $p = Product::factory()->create();

        $this->actingAs($this->userWithRole(Permissions::ROLE_READ_ONLY))
            ->put(route('product-costs.update', $p), ['cost' => 5])->assertForbidden();
    }
}
