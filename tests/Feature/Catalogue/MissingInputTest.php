<?php

namespace Tests\Feature\Catalogue;

use App\Authorization\Permissions;
use App\Models\Barcode;
use App\Models\Product;
use App\Models\User;
use Database\Factories\ProductFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Support\StockFixtures;
use Tests\TestCase;

/**
 * Missing, empty or wrong-shaped input on Batch tracking and every barcode route: a page,
 * a validation message (302 back, or 422 as JSON), or a 404, never a 500.
 */
class MissingInputTest extends TestCase
{
    use RefreshDatabase, StockFixtures;

    private User $manager;

    private Barcode $barcode;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedStockReferenceData();
        $this->manager = $this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER);
        $product = Product::factory()->withPack('Carton', 12)->create();
        $this->barcode = ProductFactory::unitOf($product, 12)->barcodes()->create(['barcode' => '9300000000019', 'type' => 'ean13']);
    }

    public function test_batch_tracking_with_no_or_bad_input()
    {
        foreach ([[], ['products' => ''], ['products' => 'abc,,1x,99999'], ['mode' => 'off'], ['mode' => 'off', 'products' => ''], ['mode' => 'nonsense']] as $query) {
            $this->actingAs($this->manager)->get(route('batch-tracking.index', $query))->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->has('rows', 0));
        }
        foreach ([[], ['products' => ''], ['products' => ['x']], ['products' => [['id' => 'x']]]] as $body) {
            $this->actingAs($this->manager)->post(route('batch-tracking.apply'), $body)->assertStatus(302)->assertSessionHasErrors();
            $this->actingAs($this->manager)->postJson(route('batch-tracking.apply'), $body)->assertStatus(422);
        }
    }

    public function test_barcode_lookup_with_no_empty_or_wrong_shaped_code()
    {
        $this->actingAs($this->manager)->get(route('barcodes.index'))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('products/BarcodeLookup')->where('code', null)->where('match', null));
        $this->actingAs($this->manager)->get(route('barcodes.index', ['code' => '']))->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->where('code', null));
        $this->actingAs($this->manager)->get(route('barcodes.index', ['code' => '   ']))->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->where('code', null));
        $this->actingAs($this->manager)->getJson(route('barcodes.index', ['code' => ['x']]))->assertStatus(422)->assertJsonValidationErrors('code');
        $this->actingAs($this->manager)->getJson(route('barcodes.index', ['code' => str_repeat('9', 101)]))->assertStatus(422);
        $this->actingAs($this->manager)->getJson(route('barcodes.lookup', ['barcode' => 'nope']))->assertNotFound();
    }

    public function test_barcode_changes_with_missing_or_empty_input()
    {
        $b = $this->barcode;
        foreach ([[], ['barcode' => ''], ['barcode' => ['x']]] as $body) {
            $this->actingAs($this->manager)->putJson(route('barcodes.update', $b), $body)->assertStatus(422)->assertJsonValidationErrors('barcode');
        }
        foreach ([[], ['product_unit_id' => ''], ['product_unit_id' => 'abc'], ['product_unit_id' => 999999]] as $body) {
            $this->actingAs($this->manager)->postJson(route('barcodes.move', $b), $body)->assertStatus(422)->assertJsonValidationErrors('product_unit_id');
        }
        $this->actingAs($this->manager)->deleteJson(route('barcodes.destroy', 999999))->assertNotFound();
        $this->actingAs($this->manager)->putJson(route('barcodes.update', 'abc'), ['barcode' => '9300000000026'])->assertNotFound();
        $this->assertSame('9300000000019', $b->fresh()->barcode);
    }

    public function test_capture_barcodes_with_missing_or_empty_input()
    {
        foreach ([[], ['product' => 'abc'], ['q' => ''], ['q' => ['x']], ['product' => 999999]] as $query) {
            $status = $this->actingAs($this->manager)->getJson(route('barcode-capture.index', $query))->status();
            $this->assertContains($status, [200, 422], 'capture page with '.json_encode($query));
        }
        foreach ([[], ['product_unit_id' => '', 'barcode' => ''], ['product_unit_id' => 'abc', 'barcode' => '9300000000026']] as $body) {
            $this->actingAs($this->manager)->postJson(route('barcode-capture.store'), $body)->assertStatus(422);
        }
        foreach ([[], ['barcode_id' => '', 'product_unit_id' => ''], ['barcode_id' => 999999, 'product_unit_id' => 999999]] as $body) {
            $this->actingAs($this->manager)->postJson(route('barcode-capture.move'), $body)->assertStatus(422);
        }
    }
}
