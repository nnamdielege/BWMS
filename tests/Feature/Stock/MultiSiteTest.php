<?php

namespace Tests\Feature\Stock;

use App\Authorization\Permissions;
use App\Models\Location;
use App\Models\Product;
use App\Services\Stock\FefoAllocator;
use App\Services\Stock\StockQuery;
use App\Services\Stock\StocktakeService;
use Database\Seeders\DefaultSiteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\StockFixtures;
use Tests\TestCase;

/**
 * One site today (Clontarf), more coming: everything must already work with
 * several sites, including repeated bin codes.
 */
class MultiSiteTest extends TestCase
{
    use RefreshDatabase, StockFixtures;

    private Location $second;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedStockReferenceData();
        $this->second = Location::create(['type' => 'site', 'code' => 'SITE2', 'name' => 'Second site', 'is_sellable' => false]);
        DefaultSiteSeeder::seedStandardBins($this->second);
    }

    public function test_default_site_is_clontarf()
    {
        $this->assertSame('Clontarf Warehouse', $this->site()->name);
    }

    public function test_bin_codes_repeat_across_sites_and_stock_is_kept_apart()
    {
        $p = Product::factory()->create();
        $this->stockIn($p, 5, $this->bin('STOCK'));
        $this->stockIn($p, 7, $this->bin('STOCK', $this->second));

        $this->assertNotSame($this->bin('STOCK')->id, $this->bin('STOCK', $this->second)->id);
        $this->assertSame(5, $this->balance($p, $this->bin('STOCK')));
        $this->assertSame(7, $this->balance($p, $this->bin('STOCK', $this->second)));
        $this->assertSame('SITE2 / STOCK', $this->bin('STOCK', $this->second)->path());
    }

    public function test_sellable_totals_span_sites_and_fefo_can_be_limited_to_one_site()
    {
        $p = Product::factory()->batchTracked()->create();
        $early = $this->batch($p, 'EARLY', '2030-01-01');
        $late = $this->batch($p, 'LATE', '2030-06-01');
        $this->stockIn($p, 4, $this->bin('STOCK', $this->second), $early);
        $this->stockIn($p, 6, $this->bin('STOCK'), $late);

        $this->assertSame(10, app(StockQuery::class)->sellableOnHand($p));

        $anywhere = app(FefoAllocator::class)->allocate($p, 5);
        $this->assertSame('EARLY', $anywhere['lines'][0]['balance']->batch->lot_code);

        $clontarfOnly = app(FefoAllocator::class)->allocate($p, 5, $this->site());
        $this->assertSame([['LATE', 5]], array_map(fn ($l) => [$l['balance']->batch->lot_code, $l['qty']], $clontarfOnly['lines']));
    }

    public function test_transfer_between_sites()
    {
        $p = Product::factory()->create();
        $this->stockIn($p, 10, $this->bin('STOCK'));

        $this->actingAs($this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER))->post(route('stock.transfer', $p), [
            'client_token' => (string) Str::uuid(),
            'from_location_id' => $this->bin('STOCK')->id,
            'to_location_id' => $this->bin('STOCK', $this->second)->id,
            'qty' => 4,
        ])->assertSessionHasNoErrors();

        $this->assertSame(6, $this->balance($p, $this->bin('STOCK')));
        $this->assertSame(4, $this->balance($p, $this->bin('STOCK', $this->second)));
    }

    public function test_stocktake_is_scoped_to_one_site()
    {
        $manager = $this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER);
        $p = Product::factory()->create();
        $this->stockIn($p, 3, $this->bin('STOCK'));
        $this->stockIn($p, 9, $this->bin('STOCK', $this->second));

        $st = app(StocktakeService::class)->create($manager, $this->second);

        $this->assertSame([$this->bin('STOCK', $this->second)->id], $st->lines()->pluck('location_id')->all());
    }
}
