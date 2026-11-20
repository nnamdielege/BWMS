<?php

namespace Tests\Feature\Reports;

use App\Authorization\Permissions;
use App\Enums\MovementType;
use App\Models\Product;
use App\Models\Range;
use App\Models\StockBalance;
use App\Models\User;
use App\Services\Reports\Insights\InsightFilters;
use App\Services\Reports\Insights\ReconciliationReport;
use App\Services\Stock\MovementDraft;
use App\Support\BusinessDate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Support\StockFixtures;
use Tests\TestCase;

class ReconciliationReportTest extends TestCase
{
    use RefreshDatabase, StockFixtures;

    private User $manager;

    private Product $milk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedStockReferenceData();
        $this->manager = $this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER);
        $this->milk = Product::factory()->create(['range_id' => Range::create(['name' => 'Dairy'])->id]);
        $this->milk->forceFill(['avg_cost' => 2])->save();
    }

    private function move(MovementType $type, int $qty, ?string $reason = null, ?string $note = null, ?string $cost = null, int $daysAgo = 1, ?string $bin = null, ?string $source = null): void
    {
        $this->ledger()->postOne(new MovementDraft(product: $this->milk, location: $this->bin($bin ?? 'STOCK'), qty: $qty, type: $type,
            idempotencyKey: 't:'.Str::uuid(), reason: $reason ? $this->reason($reason) : null, sourceType: $source, unitCost: $cost,
            occurredAt: now()->subDays($daysAgo), note: $note, allowNegative: true), $this->manager);
    }

    private function report(int $toDaysAgo = 0): array
    {
        $to = Carbon::parse(BusinessDate::today())->subDays($toDaysAgo);

        return app(ReconciliationReport::class)->build(new InsightFilters($to->copy()->subDays(6)->toDateString(), $to->toDateString()));
    }

    public function test_reconciliation_maths_and_unexplained_loss()
    {
        $this->move(MovementType::Receipt, 100, daysAgo: 20);                                // opening
        $this->move(MovementType::Receipt, 50);
        $this->move(MovementType::Sale, -30, cost: '2.0000');
        $this->move(MovementType::WriteOff, -5, 'DAMAGED');
        $this->move(MovementType::WriteOff, -3, 'SAMPLE', 'Tasting', source: 'giveaway');   // a give-away, not a write-off
        $this->move(MovementType::Adjustment, -4, 'LOST', 'Gone');                           // unexplained: "lost" explains nothing
        $this->move(MovementType::Adjustment, -2, 'CORRECTION', 'Keyed 12 not 10');          // explained
        $this->move(MovementType::Adjustment, 1, 'FOUND', 'Behind the pallet');
        $this->move(MovementType::Adjustment, -1, 'LOST', 'Missing at count');               // unexplained
        $this->move(MovementType::StocktakeVariance, -6, cost: '2.5000');                    // unexplained
        $this->move(MovementType::StocktakeVariance, 2, cost: '2.5000');
        $this->move(MovementType::TransferOut, -10);                                         // within the site: nets out
        $this->move(MovementType::TransferIn, 10, bin: 'QUARANTINE');

        $r = $this->report();
        $row = $r['rows'][0];
        $this->assertSame(
            ['opening' => 100, 'received' => 50, 'dispatched' => 30, 'write_offs' => 5, 'giveaways' => 3, 'adjustments' => -6, 'stocktake' => -4, 'other' => 0, 'expected' => 102, 'actual' => 102, 'difference' => 0],
            array_intersect_key($row, array_flip(['opening', 'received', 'dispatched', 'write_offs', 'giveaways', 'adjustments', 'stocktake', 'other', 'expected', 'actual', 'difference'])),
        );
        // 4 + 1 lost at today's $2 + 6 counted short at $2.50.
        $this->assertSame([11, 25.0, 60.0, 41.67], [$row['loss_units'], $row['loss_value'], $row['cogs'], $row['shrinkage_percent']]);
        $this->assertCount(3, $row['loss_movement_ids']);
        $this->assertSame([['range' => 'Dairy', 'loss_units' => 11, 'loss_value' => 25.0, 'cogs' => 60.0, 'shrinkage_percent' => 41.67]], $r['ranges']);
        $this->assertSame([25.0, 41.67, 0], [$r['totals']['loss_value'], $r['totals']['shrinkage_percent'], $r['totals']['mismatched']]);
        $this->assertSame($this->milk->id, $r['top'][0]['product_id']);
    }

    public function test_actual_closing_leaves_out_movements_after_the_end_date()
    {
        $this->move(MovementType::Receipt, 40, daysAgo: 3);
        $this->move(MovementType::Sale, -15, cost: '2.0000', daysAgo: 0); // after the end date

        $row = $this->report(1)['rows'][0];
        $this->assertSame([0, 40, 0, 40, 40, 0], [$row['opening'], $row['received'], $row['dispatched'], $row['expected'], $row['actual'], $row['difference']]);
        $this->assertSame(25, (int) StockBalance::where('product_id', $this->milk->id)->sum('on_hand'));
    }

    public function test_the_unexplained_rule()
    {
        $this->assertTrue(ReconciliationReport::unexplained(MovementType::StocktakeVariance, -1, null, null));
        $this->assertFalse(ReconciliationReport::unexplained(MovementType::StocktakeVariance, 3, null, null));
        $this->assertTrue(ReconciliationReport::unexplained('adjustment', -1, 'LOST', 'Gone'));
        $this->assertTrue(ReconciliationReport::unexplained('adjustment', -1, null, 'note'));
        $this->assertTrue(ReconciliationReport::unexplained('adjustment', -1, 'CORRECTION', '  '));
        $this->assertFalse(ReconciliationReport::unexplained('adjustment', -1, 'CORRECTION', 'Keyed wrong'));
        $this->assertFalse(ReconciliationReport::unexplained('adjustment', -1, 'BATCH_ENABLE', null));
        $this->assertFalse(ReconciliationReport::unexplained('adjustment', 2, 'LOST', null));
        $this->assertFalse(ReconciliationReport::unexplained('write_off', -5, 'DAMAGED', null));
        $this->assertSame(7, ReconciliationReport::expectedClosing(['opening' => 10, 'received' => 5, 'dispatched' => 4, 'write_offs' => 1, 'giveaways' => 1, 'adjustments' => -1, 'stocktake' => -2, 'other' => 1]));
    }

    public function test_page_links_loss_to_movements_and_exports()
    {
        $this->move(MovementType::Receipt, 10);
        $this->move(MovementType::StocktakeVariance, -2, cost: '2.0000');

        $ids = [];
        $this->actingAs($this->manager)->get(route('reports.reconciliation.index'))->assertOk()
            ->assertInertia(function (AssertableInertia $p) use (&$ids) {
                $p->component('reports/insights/Reconciliation')->where('report.totals.loss_value', 4)
                    ->where('report.rows.0.loss_movement_ids', function ($v) use (&$ids) {
                        $ids = collect($v)->all();

                        return count($ids) === 1;
                    });
            });
        $this->actingAs($this->manager)->get(route('stock.movements', ['ids' => implode(',', $ids)]))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->has('movements.data', 1)->where('movements.data.0.id', $ids[0]));
        foreach (['csv', 'xlsx'] as $format) {
            $this->actingAs($this->manager)->get(route('reports.reconciliation.export', ['format' => $format]))->assertOk();
        }
        $this->actingAs($this->userWithRole(Permissions::ROLE_WAREHOUSE_STAFF))->get(route('reports.reconciliation.index'))->assertForbidden();
    }
}
