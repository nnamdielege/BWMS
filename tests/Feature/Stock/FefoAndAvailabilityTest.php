<?php

namespace Tests\Feature\Stock;

use App\Models\Location;
use App\Models\Product;
use App\Services\Stock\FefoAllocator;
use App\Services\Stock\StockQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\StockFixtures;
use Tests\TestCase;

class FefoAndAvailabilityTest extends TestCase
{
    use RefreshDatabase, StockFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedStockReferenceData();
        Carbon::setTestNow(Carbon::parse('2026-11-10 09:00', 'Australia/Brisbane'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_fefo_takes_earliest_best_before_first_across_bins()
    {
        $p = Product::factory()->batchTracked()->create();
        $shelf2 = Location::factory()->create(['parent_id' => $this->bin()->parent_id, 'code' => 'SHELF-2']);

        $late = $this->batch($p, 'LATE', '2026-12-20');
        $early = $this->batch($p, 'EARLY', '2026-11-20');
        $mid = $this->batch($p, 'MID', '2026-12-01');
        $this->stockIn($p, 10, $this->bin(), $late);
        $this->stockIn($p, 4, $shelf2, $early);
        $this->stockIn($p, 6, $this->bin(), $mid);

        $result = app(FefoAllocator::class)->allocate($p, 12);

        $this->assertSame(
            [['EARLY', 4], ['MID', 6], ['LATE', 2]],
            array_map(fn ($l) => [$l['balance']->batch->lot_code, $l['qty']], $result['lines']),
        );
        $this->assertSame(0, $result['shortfall']);
    }

    public function test_fefo_skips_expired_quarantine_and_non_sellable_stock_and_reports_shortfall()
    {
        $p = Product::factory()->batchTracked()->create();
        $expired = $this->batch($p, 'OLD', '2026-11-09');  // yesterday in Brisbane
        $today = $this->batch($p, 'TODAY', '2026-11-10');  // still sellable today
        $this->stockIn($p, 5, $this->bin(), $expired);
        $this->stockIn($p, 3, $this->bin('QUARANTINE'), $today);
        $this->stockIn($p, 2, $this->bin('RECEIVING'), $today);
        $this->stockIn($p, 1, $this->bin(), $today);

        $result = app(FefoAllocator::class)->allocate($p, 4);

        $this->assertSame([['TODAY', 1]], array_map(fn ($l) => [$l['balance']->batch->lot_code, $l['qty']], $result['lines']));
        $this->assertSame(3, $result['shortfall']);
        $this->assertSame(1, app(StockQuery::class)->sellableOnHand($p));
        $this->assertSame(11, app(StockQuery::class)->onHand($p));
    }

    public function test_expiry_is_evaluated_in_brisbane_time()
    {
        $p = Product::factory()->batchTracked()->create();
        $bb = $this->batch($p, 'B', '2026-11-10');
        $this->stockIn($p, 5, $this->bin(), $bb);

        // 23:30 UTC on the 10th is already 09:30 on the 11th in Brisbane: expired.
        Carbon::setTestNow(Carbon::parse('2026-11-10 23:30', 'UTC'));
        $this->assertSame(0, app(StockQuery::class)->sellableOnHand($p));
        $this->assertTrue($bb->fresh()->isExpired());
    }

    public function test_untracked_stock_is_allocated_by_bin_order()
    {
        $p = Product::factory()->create();
        $this->stockIn($p, 2);

        $result = app(FefoAllocator::class)->allocate($p, 2);

        $this->assertSame(2, $result['lines'][0]['qty']);
        $this->assertNull($result['lines'][0]['balance']->batch);
    }
}
