<?php

namespace Tests\Feature\Reports;

use App\Authorization\Permissions;
use App\Enums\MovementType;
use App\Models\CountTask;
use App\Models\PickListLine;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Services\Reports\Insights\ExceptionsReport;
use App\Services\Reports\Insights\InsightFilters;
use App\Services\Stock\CountTaskService;
use App\Services\Stock\MovementDraft;
use App\Services\Stock\StocktakeService;
use App\Support\BusinessDate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Support\OrderFixtures;
use Tests\Support\StockFixtures;
use Tests\TestCase;

class ExceptionsReportTest extends TestCase
{
    use OrderFixtures, RefreshDatabase, StockFixtures;

    private User $manager;

    private Product $milk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedStockReferenceData();
        $this->manager = $this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER);
        $this->milk = Product::factory()->create();
        $this->milk->forceFill(['avg_cost' => 2])->save();
    }

    private function report(): array
    {
        $to = BusinessDate::today();

        return app(ExceptionsReport::class)->build(new InsightFilters(Carbon::parse($to)->subDays(13)->toDateString(), $to));
    }

    /** A movement at a Brisbane local time. */
    private function move(MovementType $type, int $qty, Carbon $local, ?string $reason = null, ?string $note = null, ?User $by = null, string $bin = 'STOCK'): void
    {
        $this->ledger()->postOne(new MovementDraft(product: $this->milk, location: $this->bin($bin), qty: $qty, type: $type, idempotencyKey: 't:'.Str::uuid(),
            reason: $reason ? $this->reason($reason) : null, occurredAt: $local->copy()->utc(), note: $note, allowNegative: true), $by ?? $this->manager);
    }

    private function local(string $relative): Carbon
    {
        return Carbon::parse($relative, config('bwms.business_timezone'));
    }

    public function test_after_hours_and_weekend_adjustments_are_flagged_by_user_reason_and_hour()
    {
        $night = $this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER);
        $saturday = $this->local('last saturday 11:00');
        $weekdayLate = $this->local('last wednesday 21:30');
        $weekday = $this->local('last wednesday 10:00');
        $this->move(MovementType::Receipt, 100, $weekday->copy()->subDays(7));
        $this->move(MovementType::WriteOff, -3, $saturday, 'DAMAGED', null, $night);
        $this->move(MovementType::Adjustment, -2, $weekdayLate, 'LOST', 'Gone', $night);
        $this->move(MovementType::WriteOff, -1, $weekday, 'DAMAGED');

        $r = $this->report();
        $this->assertSame(3, $r['totals']['adjustments']);
        $this->assertSame(2, $r['totals']['out_of_hours']);
        $user = collect($r['by_user'])->firstWhere('label', $night->name);
        $this->assertSame([2, 5, 10.0, 1, 1], [$user['count'], $user['units_out'], $user['value_out'], $user['after_hours'], $user['weekend']]);
        $this->assertCount(2, $user['flag_ids']);
        $this->assertSame(0, collect($r['by_user'])->firstWhere('label', $this->manager->name)['after_hours']);
        $this->assertSame(2, collect($r['by_reason'])->firstWhere('label', 'Damaged / broken')['count']);
        $this->assertSame(1, $r['by_hour'][21]['count']);
        $this->assertTrue($r['by_hour'][21]['after_hours']);
        $this->assertFalse($r['by_hour'][10]['after_hours']);

        // Working hours are a setting: 06-22 makes 21:30 inside them.
        Setting::put([ExceptionsReport::WORK_HOURS => '06:00-22:00']);
        $this->assertSame(1, $this->report()['totals']['out_of_hours']);
        $this->assertTrue(ExceptionsReport::afterHours($this->local('2026-11-18 05:59'), [6, 18]));
        $this->assertTrue(ExceptionsReport::afterHours($this->local('2026-11-18 18:00'), [6, 18]));
        $this->assertFalse(ExceptionsReport::afterHours($this->local('2026-11-18 17:59'), [6, 18]));
    }

    public function test_repeated_losses_on_a_sku_or_bin_and_bins_that_go_negative()
    {
        $day = $this->local('yesterday 10:00');
        $this->move(MovementType::Receipt, 50, $day->copy()->subDays(3));
        $this->move(MovementType::StocktakeVariance, -2, $day);
        $this->move(MovementType::Adjustment, -1, $day->copy()->addHour(), 'LOST', 'Missing');
        $this->move(MovementType::Adjustment, -1, $day->copy()->addHours(2), 'CORRECTION', 'Keyed wrong'); // explained: not counted
        $this->move(MovementType::StocktakeVariance, -4, $day, null, null, null, 'QUARANTINE');               // other bin, once

        $r = $this->report();
        $this->assertSame([[$this->milk->id, 3, 7, 14.0]], array_map(fn ($s) => [$s['product_id'], $s['times'], $s['units'], $s['value']], $r['repeated']['skus']));
        $this->assertCount(1, $r['repeated']['bins']);
        $this->assertSame([2, 3], [$r['repeated']['bins'][0]['times'], $r['repeated']['bins'][0]['units']]);
        $this->assertCount(2, $r['repeated']['bins'][0]['ids']);

        // A dispatch took a bin negative (count task), and a bin is negative now.
        app(CountTaskService::class)->raise($this->bin(), $this->milk, CountTask::REASON_NEGATIVE_AFTER_DISPATCH, 'pick_list', 1, -3);
        $this->move(MovementType::Sale, -10, $day, null, null, null, 'QUARANTINE');
        $neg = collect($this->report()['negative_bins'])->keyBy('bin');
        $this->assertSame([-3, 1, null], [$neg['STOCK']['lowest'], $neg['STOCK']['times'], $neg['STOCK']['on_hand_now']]);
        $this->assertSame(-14, $neg['QUARANTINE']['on_hand_now']);
        $this->assertNotEmpty($neg['QUARANTINE']['ids']);
    }

    public function test_short_and_unscanned_picks_by_picker()
    {
        $this->stockIn($this->milk, 100);
        $picker = $this->userWithRole(Permissions::ROLE_WAREHOUSE_STAFF);
        $short = $this->dispatchedSale($this->manager, $this->milk, 10, null, '5.00', picked: 7);
        $scanless = $this->dispatchedSale($this->manager, $this->milk, 4, null, '5.00');
        PickListLine::whereHas('orderLine', fn ($q) => $q->where('order_id', $short->id))->update(['picked_by' => $picker->id]);
        PickListLine::whereHas('orderLine', fn ($q) => $q->where('order_id', $scanless->id))->update(['picked_by' => $picker->id, 'override_reason' => 'label_damaged']);

        $r = $this->report();
        $this->assertSame([1, 1], [$r['totals']['short_picks'], $r['totals']['unscanned_picks']]);
        $this->assertSame([$picker->name, $this->milk->id, 1, 3], [$r['short_picks'][0]['picker'], $r['short_picks'][0]['product_id'], $r['short_picks'][0]['lines'], $r['short_picks'][0]['units_short']]);
        $this->assertSame([$picker->name, 'label damaged', 1], [$r['unscanned_picks'][0]['picker'], $r['unscanned_picks'][0]['reason'], $r['unscanned_picks'][0]['lines']]);
        $this->assertCount(1, $r['unscanned_picks'][0]['pick_list_ids']);
    }

    public function test_stocktake_lines_counted_and_posted_by_one_person_with_a_large_variance()
    {
        $this->stockIn($this->milk, 100);
        $other = $this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER);
        $service = app(StocktakeService::class);
        foreach ([[$this->manager, 70], [$other, 95]] as [$counter, $counted]) {
            $st = $service->create($this->manager, $this->site());
            $service->recordCount($st->lines()->first(), $counted, $counter);
            $service->post($st->fresh(), $this->manager);
        }

        // Self-counted, 30 short at $2 = $60 (≥ $50). The second was counted by someone else.
        $r = $this->report();
        $this->assertSame([[$this->manager->name, -30, -60.0]], array_map(fn ($l) => [$l['user'], $l['variance'], $l['value']], $r['self_counted']));
        $this->assertCount(1, $r['self_counted'][0]['ids']);
        Setting::put([ExceptionsReport::LARGE_VARIANCE => '100']);
        $this->assertSame([], $this->report()['self_counted']);
    }

    public function test_page_settings_and_exports_for_managers_only()
    {
        $this->actingAs($this->manager)->get(route('reports.exceptions.index'))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('reports/insights/Exceptions')->where('report.work_hours', '06:00-18:00')->has('report.by_hour', 24));
        $this->actingAs($this->manager)->put(route('reports.exceptions.settings'), ['start_hour' => 7, 'end_hour' => 5, 'large_variance' => 20])->assertSessionHasErrors('end_hour');
        $this->actingAs($this->manager)->put(route('reports.exceptions.settings'), ['start_hour' => 7, 'end_hour' => 19, 'large_variance' => 20])->assertSessionHasNoErrors();
        $this->assertSame([[7, 19], 20.0], [ExceptionsReport::workHours(), ExceptionsReport::largeVariance()]);
        foreach (['csv', 'xlsx'] as $format) {
            $this->actingAs($this->manager)->get(route('reports.exceptions.export', ['format' => $format]))->assertOk();
        }
        $staff = $this->userWithRole(Permissions::ROLE_WAREHOUSE_STAFF);
        $this->actingAs($staff)->get(route('reports.exceptions.index'))->assertForbidden();
        $this->actingAs($staff)->put(route('reports.exceptions.settings'), ['start_hour' => 1, 'end_hour' => 2, 'large_variance' => 1])->assertForbidden();
    }
}
