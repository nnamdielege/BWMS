<?php

namespace Tests\Feature\Stock;

use App\Authorization\Permissions;
use App\Enums\MovementType;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Stocktake;
use App\Services\Stock\Exceptions\InvalidMovement;
use App\Services\Stock\MovementDraft;
use App\Services\Stock\StocktakeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\StockFixtures;
use Tests\TestCase;

class StocktakeTest extends TestCase
{
    use RefreshDatabase, StockFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedStockReferenceData();
    }

    private function service(): StocktakeService
    {
        return app(StocktakeService::class);
    }

    public function test_full_cycle_posts_variances_as_ledger_movements()
    {
        $manager = $this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER);
        $a = Product::factory()->create();
        $b = Product::factory()->create();
        $this->stockIn($a, 10);
        $this->stockIn($b, 5);

        $st = $this->service()->create($manager, $this->bin()->parent);
        $this->assertSame(2, $st->lines()->count());

        foreach ($st->lines as $line) {
            $this->service()->recordCount($line, $line->product_id === $a->id ? 8 : 5, $manager);
        }
        $this->service()->post($st, $manager);

        $this->assertSame(8, $this->balance($a));
        $this->assertSame(5, $this->balance($b));
        $variance = StockMovement::where('type', MovementType::StocktakeVariance)->sole();
        $this->assertSame(-2, $variance->qty);
        $this->assertSame(Stocktake::STATUS_POSTED, $st->fresh()->status);
    }

    public function test_movements_during_counting_are_not_double_counted()
    {
        $manager = $this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER);
        $p = Product::factory()->create();
        $this->stockIn($p, 10);
        $st = $this->service()->create($manager, $this->bin());
        $line = $st->lines()->sole();

        // A sale of 3 happens before the counter reaches the shelf; they count 7 (correct).
        $this->ledger()->postOne(new MovementDraft($p, $this->bin(), -3, MovementType::Sale, 'sale-during-count'));
        $this->service()->recordCount($line, 7, $manager);
        // Another sale after the count, before posting.
        $this->ledger()->postOne(new MovementDraft($p, $this->bin(), -2, MovementType::Sale, 'sale-after-count'));

        $this->service()->post($st, $manager);

        $this->assertSame(0, $line->fresh()->posted_variance, 'Count matched the system at count time.');
        $this->assertSame(5, $this->balance($p));
    }

    public function test_uncounted_lines_block_posting_unless_zeroed()
    {
        $manager = $this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER);
        $p = Product::factory()->create();
        $this->stockIn($p, 4);
        $st = $this->service()->create($manager, $this->bin());

        try {
            $this->service()->post($st, $manager);
            $this->fail('Expected uncounted lines to block posting');
        } catch (InvalidMovement) {
        }
        $this->assertSame(4, $this->balance($p));

        $this->service()->post($st, $manager, zeroUncounted: true);
        $this->assertSame(0, $this->balance($p));
    }

    public function test_found_stock_line_and_batch()
    {
        $manager = $this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER);
        $p = Product::factory()->batchTracked()->create();
        $st = $this->service()->create($manager, $this->bin()->parent);

        $this->actingAs($manager)->post(route('stocktakes.lines.store', $st), [
            'sku' => $p->sku, 'location_id' => $this->bin()->id, 'lot_code' => 'F1', 'best_before' => '2027-02-01',
        ])->assertSessionHasNoErrors();
        $line = $st->lines()->sole();
        $this->actingAs($manager)->put(route('stocktakes.count', [$st, $line]), ['counted_qty' => 12])->assertSessionHasNoErrors();
        $this->actingAs($manager)->post(route('stocktakes.post', $st))->assertSessionHasNoErrors();

        $this->assertSame(12, $this->balance($p, null, $p->batches()->sole()));
    }

    public function test_posted_or_cancelled_stocktakes_are_frozen_and_posting_twice_is_impossible()
    {
        $manager = $this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER);
        $p = Product::factory()->create();
        $this->stockIn($p, 4);
        $st = $this->service()->create($manager, $this->bin());
        $this->service()->recordCount($st->lines()->sole(), 3, $manager);
        $this->service()->post($st, $manager);

        $this->expectException(InvalidMovement::class);
        $this->service()->post($st->fresh(), $manager);
    }

    public function test_staff_can_count_but_not_post()
    {
        $manager = $this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER);
        $staff = $this->userWithRole(Permissions::ROLE_WAREHOUSE_STAFF);
        $p = Product::factory()->create();
        $this->stockIn($p, 4);
        $st = $this->service()->create($manager, $this->bin());

        $this->actingAs($staff)->get(route('stocktakes.show', $st))->assertOk();
        $this->actingAs($staff)->put(route('stocktakes.count', [$st, $st->lines()->sole()]), ['counted_qty' => 4])->assertSessionHasNoErrors();
        $this->actingAs($staff)->post(route('stocktakes.post', $st))->assertForbidden();
        $this->actingAs($staff)->post(route('stocktakes.store'), ['scope_location_id' => $this->bin()->id])->assertForbidden();
    }
}
