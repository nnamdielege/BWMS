<?php

namespace Tests\Feature\Catalogue;

use App\Authorization\Permissions;
use App\Models\AuditLog;
use App\Models\Barcode;
use App\Models\Location;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Support\StockFixtures;
use Tests\TestCase;

class BarcodeCaptureTest extends TestCase
{
    use RefreshDatabase, StockFixtures;

    private const GOOD = '9300000000019';

    private const OTHER = '9300000000026';

    private User $manager;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedStockReferenceData();
        $this->manager = $this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER);
        $this->product = Product::factory()->withPack('Carton', 12)->create(['sku' => 'MILK-1', 'name' => 'Oat milk']);
    }

    private function save(string $code, ?int $unit = null)
    {
        return $this->actingAs($this->manager)->post(route('barcode-capture.store'), ['product_unit_id' => $unit ?? $this->carton(), 'barcode' => $code]);
    }

    private function carton(): int
    {
        return $this->product->units()->where('base_qty', 12)->value('id');
    }

    private function page(array $query = [])
    {
        return $this->actingAs($this->manager)->get(route('barcode-capture.index', $query));
    }

    private function bin(string $code, int $walk): Location
    {
        return Location::create(['type' => 'bin', 'parent_id' => $this->site()->id, 'code' => $code, 'name' => $code, 'is_sellable' => true, 'walk_seq' => $walk])->refresh();
    }

    public function test_a_barcode_saves_on_the_pack_straight_away_and_returns_to_the_product()
    {
        $this->save(self::GOOD)->assertRedirect(route('barcode-capture.index', ['product' => $this->product->id]));

        $barcode = Barcode::sole();
        $this->assertSame([self::GOOD, 'ean13', $this->carton()], [$barcode->barcode, $barcode->type, $barcode->product_unit_id]);
        $this->assertSame(1, AuditLog::where('auditable_type', (new Barcode)->getMorphClass())->where('event', 'created')->count());
    }

    public function test_a_wrong_check_digit_is_refused()
    {
        $this->save('9300000000018')->assertSessionHasErrors(['barcode' => '9300000000018 has a wrong check digit. Scan it again.']);
        $this->save('96385074')->assertSessionHasNoErrors(); // valid EAN-8
        $this->save('96385075')->assertSessionHasErrors('barcode');

        $this->assertSame(1, Barcode::count());
    }

    public function test_a_duplicate_is_refused_and_says_which_product_has_it()
    {
        $other = Product::factory()->create(['sku' => 'SOY-1', 'name' => 'Soy milk']);
        $other->units()->first()->barcodes()->create(['barcode' => self::GOOD, 'type' => 'ean13']);

        $this->save(self::GOOD)->assertSessionHasErrors(['barcode' => self::GOOD.' is already on SOY-1 Soy milk (Each).']);

        $this->assertSame(1, Barcode::count());
    }

    public function test_the_same_code_on_another_pack_of_this_product_is_refused_too()
    {
        $this->save(self::GOOD);

        $this->save(self::GOOD, $this->product->units()->where('base_qty', 1)->value('id'))->assertSessionHasErrors(['barcode' => self::GOOD.' is already on MILK-1 Oat milk (Carton).']);
        $this->save(self::GOOD)->assertSessionHasErrors(['barcode' => self::GOOD.' is already on this pack.']);
    }

    public function test_a_bin_label_scanned_by_mistake_is_refused()
    {
        $bin = $this->bin('A-1', 10);

        $this->save($bin->barcode)->assertSessionHasErrors(['barcode' => "That's the label for bin A-1, not a product."]);
    }

    public function test_rubbish_is_refused()
    {
        $this->save('not a code!')->assertSessionHasErrors('barcode');
    }

    public function test_a_pack_can_hold_a_second_barcode()
    {
        $this->save(self::GOOD);
        $this->save(self::OTHER)->assertSessionHasNoErrors();

        $this->assertSame(2, Barcode::count());
    }

    public function test_progress_counts_active_stock_tracked_products_with_any_barcode()
    {
        Product::factory()->create(['sku' => 'OLD-1', 'is_active' => false]);
        Product::factory()->create(['sku' => 'SERVICE-1', 'is_stock_tracked' => false]);
        Product::factory()->create(['sku' => 'TEA-1']);

        $this->page()->assertInertia(fn (AssertableInertia $p) => $p->component('products/CaptureBarcodes')->where('progress', ['have' => 0, 'total' => 2]));

        $this->save(self::GOOD);

        $this->page()->assertInertia(fn (AssertableInertia $p) => $p->where('progress', ['have' => 1, 'total' => 2]));
    }

    public function test_the_missing_list_runs_in_bin_walk_order_with_no_stock_last()
    {
        $far = Product::factory()->create(['sku' => 'A-FAR']);
        $near = Product::factory()->create(['sku' => 'Z-NEAR']);
        Product::factory()->create(['sku' => 'B-NONE']);
        $this->product->units()->where('base_qty', 12)->first()->barcodes()->create(['barcode' => self::GOOD, 'type' => 'ean13']);
        $binNear = $this->bin('A-1', 10);
        $binFar = $this->bin('Z-9', 90);
        $this->stockIn($far, 5, $binFar);
        $this->stockIn($near, 5, $binNear);
        $this->stockIn($near, 5, $binFar);

        $this->page()->assertInertia(fn (AssertableInertia $p) => $p->where('missing', fn ($rows) => collect($rows)->map(fn ($r) => [$r['sku'], $r['bin']])->all() === [
            ['Z-NEAR', 'A-1'], ['A-FAR', 'Z-9'], ['B-NONE', null],
        ]));
    }

    public function test_a_scan_finds_a_product_by_barcode_or_sku_and_words_search()
    {
        $this->product->units()->first()->barcodes()->create(['barcode' => self::GOOD, 'type' => 'ean13']);
        Product::factory()->create(['sku' => 'OAT-2', 'name' => 'Oat bar']);

        $this->page(['q' => self::GOOD])->assertInertia(fn (AssertableInertia $p) => $p->where('product.sku', 'MILK-1')->where('product.units.1.name', 'Carton'));
        $this->page(['q' => 'milk-1'])->assertInertia(fn (AssertableInertia $p) => $p->where('product.sku', 'MILK-1'));
        $this->page(['q' => 'oat'])->assertInertia(fn (AssertableInertia $p) => $p->where('product', null)->has('results', 2));
        $this->page(['q' => 'nothing-like-this'])->assertInertia(fn (AssertableInertia $p) => $p->where('product', null)->where('results', [])->where('query', 'nothing-like-this'));
    }

    public function test_it_is_for_managers_and_admins()
    {
        foreach ([Permissions::ROLE_WAREHOUSE_STAFF, Permissions::ROLE_PURCHASING, Permissions::ROLE_READ_ONLY] as $role) {
            $user = $this->userWithRole($role);
            $this->actingAs($user)->get(route('barcode-capture.index'))->assertForbidden();
            $this->actingAs($user)->post(route('barcode-capture.store'), ['product_unit_id' => $this->carton(), 'barcode' => self::GOOD])->assertForbidden();
        }
    }

    public function test_the_handheld_menu_offers_it_only_to_those_who_can_use_it()
    {
        $this->actingAs($this->manager)->get(route('pick.queue'))->assertInertia(fn (AssertableInertia $p) => $p->where('nav', fn ($nav) => collect($nav['links'])->pluck('title')->contains('Capture barcodes')));
        $this->actingAs($this->userWithRole(Permissions::ROLE_WAREHOUSE_STAFF))->get(route('pick.queue'))->assertInertia(fn (AssertableInertia $p) => $p->where('nav', fn ($nav) => ! collect($nav['links'])->pluck('title')->contains('Capture barcodes')));
    }
}
