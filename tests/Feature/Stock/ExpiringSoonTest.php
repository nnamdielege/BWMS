<?php

namespace Tests\Feature\Stock;

use App\Authorization\Permissions;
use App\Mail\ReorderSummaryMail;
use App\Models\Batch;
use App\Models\Product;
use App\Models\Setting;
use App\Services\Purchasing\ReorderPlanner;
use App\Services\Stock\ExpiringSoon;
use App\Support\BusinessDate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\Support\StockFixtures;
use Tests\TestCase;

class ExpiringSoonTest extends TestCase
{
    use RefreshDatabase, StockFixtures;

    private Product $milk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedStockReferenceData();
        $this->milk = Product::factory()->batchTracked()->create();
        $this->milk->forceFill(['avg_cost' => 2])->save();
    }

    private function inDays(int $days): string
    {
        return Carbon::parse(BusinessDate::today())->addDays($days)->toDateString();
    }

    /** Sales rate: the reorder velocity, stubbed. */
    private function selling(array $perDay): void
    {
        $this->mock(ReorderPlanner::class, fn ($m) => $m->shouldReceive('dailyVelocity')->andReturnUsing(fn (array $ids) => array_intersect_key($perDay, array_flip($ids)) + array_fill_keys($ids, 0.0)));
    }

    private function row(array $report, Batch $b): ?array
    {
        return collect($report['rows'])->firstWhere('batch_id', $b->id);
    }

    public function test_fefo_stock_ahead_decides_whether_a_batch_sells_before_its_best_before()
    {
        $first = $this->batch($this->milk, 'A', $this->inDays(3));
        $second = $this->batch($this->milk, 'B', $this->inDays(9));
        $later = $this->batch($this->milk, 'C', $this->inDays(40)); // outside 14 days: not listed
        $this->stockIn($this->milk, 6, null, $first);
        $this->stockIn($this->milk, 20, null, $second);
        $this->stockIn($this->milk, 50, null, $later);
        $this->selling([$this->milk->id => 2.0]);

        $r = app(ExpiringSoon::class)->build();
        $this->assertSame(14, $r['days']);
        $this->assertCount(2, $r['rows']);

        // A: 4 selling days (today .. best-before) × 2 = 8 ≥ 6.
        $a = $this->row($r, $first);
        $this->assertSame([0, 6, 0, ExpiringSoon::STATUS_OK], [$a['ahead'], $a['expected_sold'], $a['left_over'], $a['status']]);
        // B: 10 days × 2 = 20, less the 6 of A ahead = 14 of 20 sold, 6 left at $2.
        $b = $this->row($r, $second);
        $this->assertSame([6, 14, 6, ExpiringSoon::STATUS_AT_RISK, 12.0], [$b['ahead'], $b['expected_sold'], $b['left_over'], $b['status'], $b['value_left']]);
        $this->assertSame(9, $b['days_left']);
        $this->assertSame(['batches' => 2, 'at_risk' => 1, 'expired' => 0, 'units_left' => 6, 'value_left' => 12.0], $r['totals']);
    }

    public function test_unknown_lot_is_picked_first_and_expired_stock_holds_nothing_up()
    {
        $unknown = Batch::create(['product_id' => $this->milk->id, 'lot_code' => Batch::UNKNOWN_LOT, 'best_before' => null]);
        $past = $this->batch($this->milk, 'OLD', $this->inDays(-2));
        $soon = $this->batch($this->milk, 'S', $this->inDays(4));
        $this->stockIn($this->milk, 5, null, $unknown);
        $this->stockIn($this->milk, 7, null, $past);
        $this->stockIn($this->milk, 10, null, $soon);
        $this->selling([$this->milk->id => 3.0]);

        $r = app(ExpiringSoon::class)->build();
        $old = $this->row($r, $past);
        $this->assertSame(ExpiringSoon::STATUS_EXPIRED, $old['status']);
        $this->assertSame(7, $old['left_over']);
        $this->assertSame(-2, $old['days_left']);
        // 5 days × 3 = 15, less the 5 UNKNOWN ahead (not the expired 7) = 10: all sell.
        $s = $this->row($r, $soon);
        $this->assertSame(5, $s['ahead']);
        $this->assertSame(ExpiringSoon::STATUS_OK, $s['status']);
    }

    public function test_quarantine_stock_and_other_settings()
    {
        $soon = $this->batch($this->milk, 'S', $this->inDays(20));
        $this->stockIn($this->milk, 10, $this->bin('QUARANTINE'), $soon);
        $this->stockIn($this->milk, 4, null, $soon);
        $this->selling([]);

        $this->assertSame([], app(ExpiringSoon::class)->build()['rows']); // 20 days out, default 14

        $manager = $this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER);
        $this->actingAs($manager)->put(route('expiring.settings'), ['days' => 21, 'in_email' => false])->assertSessionHasNoErrors();
        $this->assertSame(21, Setting::expiringDays());
        $this->assertFalse(Setting::expiringInEmail());

        $r = app(ExpiringSoon::class)->build();
        $this->assertSame(4, $r['rows'][0]['on_hand']); // quarantine isn't sellable stock
        $this->assertSame(4, $r['rows'][0]['left_over']); // nothing selling

        $this->actingAs($this->userWithRole(Permissions::ROLE_WAREHOUSE_STAFF))->put(route('expiring.settings'), ['days' => 5, 'in_email' => true])->assertForbidden();
    }

    public function test_page_hides_costs_from_staff_and_dashboard_shows_the_count()
    {
        $soon = $this->batch($this->milk, 'S', $this->inDays(2));
        $this->stockIn($this->milk, 10, null, $soon); // no sales: none of it sells

        $this->actingAs($this->userWithRole(Permissions::ROLE_WAREHOUSE_STAFF))->get(route('expiring.index'))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('stock/Expiring')->where('report.rows.0.value_left', null)->where('can.costs', false));
        $manager = $this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER);
        $this->actingAs($manager)->get(route('expiring.index'))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('report.rows.0.value_left', 20)->where('can.settings', true));

        $this->actingAs($manager)->get(route('dashboard'))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('widgets.expiring', ['days' => 14, 'batches' => 1, 'at_risk' => 1, 'expired' => 0]));
    }

    public function test_the_7am_email_lists_batches_that_wont_sell_unless_switched_off()
    {
        Mail::fake();
        Setting::put([Setting::REORDER_EMAIL => '1']);
        $soon = $this->batch($this->milk, 'S', $this->inDays(2));
        $this->stockIn($this->milk, 10, null, $soon);

        $this->artisan('reorder:daily-email')->assertSuccessful();
        Mail::assertSent(ReorderSummaryMail::class, fn ($m) => $m->expiring['lines'][0]['batch'] === 'S' && $m->expiring['lines'][0]['left_over'] === 10
            && str_contains($m->render(), 'Expiring soon'));

        Setting::put([Setting::EXPIRING_IN_EMAIL => '0']);
        $this->artisan('reorder:daily-email')->assertSuccessful();
        Mail::assertSent(ReorderSummaryMail::class, fn ($m) => $m->expiring === null && ! str_contains($m->render(), 'Expiring soon'));
    }
}
