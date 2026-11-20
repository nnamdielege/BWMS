<?php

namespace Tests\Feature\Reports;

use App\Authorization\Permissions;
use App\Enums\MovementType;
use App\Models\Product;
use App\Models\Range;
use App\Models\Setting;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Reports\SpoilageReport;
use App\Services\Stock\AdjustmentService;
use App\Services\Stock\GiveawayService;
use App\Services\Stock\MovementDraft;
use App\Support\BusinessDate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Support\StockFixtures;
use Tests\TestCase;

class SpoilageReportTest extends TestCase
{
    use RefreshDatabase, StockFixtures;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedStockReferenceData();
        $this->manager = $this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER);
    }

    private function product(float $avgCost, array $attrs = []): Product
    {
        $p = Product::factory()->create($attrs);
        $p->forceFill(['avg_cost' => $avgCost])->save();

        return $p->fresh();
    }

    /** A write-off through the normal adjustment path (keeps today's cost). */
    private function writeOff(Product $p, int $qty, string $reason = 'DAMAGED', ?string $note = null): StockMovement
    {
        return app(AdjustmentService::class)->adjust($this->manager, $p, $this->bin(), -$qty, $this->reason($reason), $note, (string) Str::uuid());
    }

    /** A sample, gift or the like, through Stock → Give away. */
    private function giveAway(Product $p, int $qty, string $reason): void
    {
        app(GiveawayService::class)->record($this->manager, $p, null, $qty, $this->bin(), null, $this->reason($reason), null, 'Office',
            'Tasting', $this->manager, (string) Str::uuid());
    }

    /** A write-off as older ones were posted: no cost kept on the movement. */
    private function legacyWriteOff(Product $p, int $qty, string $reason = 'EXPIRED'): StockMovement
    {
        return $this->ledger()->postOne(new MovementDraft(product: $p, location: $this->bin(), qty: -$qty, type: MovementType::WriteOff,
            idempotencyKey: 'legacy:'.Str::uuid(), reason: $this->reason($reason)));
    }

    private function sale(Product $p, int $qty, string $cost): void
    {
        $this->ledger()->postOne(new MovementDraft(product: $p, location: $this->bin(), qty: -$qty, type: MovementType::Sale,
            idempotencyKey: 'sale:'.Str::uuid(), unitCost: $cost));
    }

    private function report(?string $from = null, ?string $to = null, string $period = 'week'): array
    {
        $today = BusinessDate::today();

        return app(SpoilageReport::class)->build($from ?? Carbon::parse($today)->subDays(27)->toDateString(), $to ?? $today, $period);
    }

    public function test_write_off_keeps_its_cost_and_is_valued_at_it_not_todays_cost()
    {
        $p = $this->product(2.50);
        $this->stockIn($p, 20);
        $m = $this->writeOff($p, 4);
        $this->assertSame('2.5000', $m->unit_cost);

        $p->forceFill(['avg_cost' => 9])->save(); // cost goes up afterwards

        $row = $this->report()['rows'][0];
        $this->assertSame(4, $row['units']);
        $this->assertSame(2.5, $row['unit_cost']);
        $this->assertSame(10.0, $row['value']);
        $this->assertSame(SpoilageReport::COST_MOVEMENT, $row['cost_basis']);
    }

    public function test_write_off_with_no_kept_cost_falls_back_to_todays_cost_and_is_marked()
    {
        $known = $this->product(3);
        $unknown = $this->product(0);
        $this->stockIn($known, 10);
        $this->stockIn($unknown, 10);
        $this->legacyWriteOff($known, 2);
        $this->writeOff($unknown, 1); // no cost known when written off: nothing kept

        $r = $this->report();
        $rows = collect($r['rows'])->keyBy('product_id');
        $this->assertSame(SpoilageReport::COST_CURRENT, $rows[$known->id]['cost_basis']);
        $this->assertSame(6.0, $rows[$known->id]['value']);
        $this->assertSame(SpoilageReport::COST_NONE, $rows[$unknown->id]['cost_basis']);
        $this->assertNull(StockMovement::where('product_id', $unknown->id)->where('type', 'write_off')->value('unit_cost'));
        $this->assertSame(0.0, $rows[$unknown->id]['value']);
        $this->assertSame(1, $r['totals']['at_current_cost']);
        $this->assertSame(1, $r['totals']['no_cost']);
    }

    public function test_groups_by_reason_product_range_and_supplier_with_shares()
    {
        $juices = Range::create(['name' => 'Juices']);
        $dairyCo = Supplier::factory()->create(['name' => 'Dairy Co']);
        $farm = Supplier::factory()->create(['name' => 'Farm Direct']);
        $milk = $this->product(2, ['range_id' => $juices->id, 'supplier_id' => $dairyCo->id]);
        $juice = $this->product(1, ['range_id' => $juices->id]);
        $yoghurt = $this->product(5, ['is_batch_tracked' => true, 'supplier_id' => $dairyCo->id]);
        $batch = $this->batch($yoghurt, 'Y1', Carbon::parse(BusinessDate::today())->addDays(30)->toDateString());
        $batch->forceFill(['supplier_id' => $farm->id])->save(); // the batch came from someone else this time

        foreach ([[$milk, 10], [$juice, 10]] as [$p, $q]) {
            $this->stockIn($p, $q);
        }
        $this->stockIn($yoghurt, 10, null, $batch);
        $this->writeOff($milk, 5);                // $10 damaged
        $this->writeOff($juice, 6, 'EXPIRED');    // $6 past best-before
        app(AdjustmentService::class)->adjust($this->manager, $yoghurt, $this->bin(), -4, $this->reason('SPOILED'), 'Warm delivery', (string) Str::uuid(), $batch); // $20

        $r = $this->report();
        $this->assertSame(36.0, $r['totals']['value']);
        $this->assertSame(15, $r['totals']['units']);

        $byReason = collect($r['groups']['reason'])->keyBy('label');
        $this->assertSame(['Spoiled (cold chain / quality)', 'Damaged / broken', 'Past best-before'], array_column($r['groups']['reason'], 'label'));
        $this->assertSame(20.0, $byReason['Spoiled (cold chain / quality)']['value']);
        $this->assertSame(55.6, $byReason['Spoiled (cold chain / quality)']['share']);

        $byRange = collect($r['groups']['range'])->keyBy('label');
        $this->assertSame(16.0, $byRange['Juices']['value']);
        $this->assertSame(20.0, $byRange['No range']['value']);

        // Supplier: the batch's supplier first, else the product's usual one.
        $bySupplier = collect($r['groups']['supplier'])->keyBy('label');
        $this->assertSame(20.0, $bySupplier['Farm Direct']['value']);
        $this->assertSame(10.0, $bySupplier['Dairy Co']['value']);
        $this->assertSame(6.0, $bySupplier['No supplier']['value']);

        $this->assertSame([$yoghurt->id, $milk->id, $juice->id], array_map('intval', array_column($r['top'], 'key')));
    }

    public function test_spoilage_percent_of_cost_of_goods_sold_in_the_same_dates()
    {
        $p = $this->product(2);
        $this->stockIn($p, 100);
        $this->sale($p, 20, '2.0000');  // $40 COGS
        $this->sale($p, 5, '2.0000');   // $10
        $this->writeOff($p, 5);         // $10 written off

        $t = $this->report()['totals'];
        $this->assertSame(50.0, $t['cogs']);
        $this->assertSame(20.0, $t['percent_of_cogs']);
    }

    public function test_dates_and_weekly_trend_include_empty_weeks()
    {
        $p = $this->product(1);
        $this->stockIn($p, 50);
        $this->travelTo(Carbon::parse('2026-11-02 10:00', 'Australia/Brisbane')); // a Monday
        $this->writeOff($p, 3);
        $this->travelTo(Carbon::parse('2026-11-18 10:00', 'Australia/Brisbane'));
        $this->writeOff($p, 2);
        $this->travelTo(Carbon::parse('2026-12-02 10:00', 'Australia/Brisbane'));
        $this->writeOff($p, 7); // after the dates

        $r = $this->report('2026-11-01', '2026-11-20', 'week');
        $this->assertSame(5, $r['totals']['units']);
        $this->assertSame(['2026-10-26', '2026-11-02', '2026-11-09', '2026-11-16'], array_column($r['trend'], 'start'));
        $this->assertSame([0.0, 3.0, 0.0, 2.0], array_column($r['trend'], 'value'));

        $monthly = $this->report('2026-11-01', '2026-12-31', 'month');
        $this->assertSame([5.0, 7.0], array_column($monthly['trend'], 'value'));
    }

    public function test_page_and_exports_for_managers_only()
    {
        $p = $this->product(2);
        $this->stockIn($p, 10);
        $this->writeOff($p, 3);

        $this->actingAs($this->manager)->get(route('reports.spoilage.index'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('reports/Spoilage')->where('report.totals.value', 6)->where('filters.group', 'reason'));

        $csv = $this->actingAs($this->manager)->get(route('reports.spoilage.export', ['format' => 'csv', 'group' => 'product']))->assertOk();
        $this->assertStringContainsString('Total written off', $csv->getContent());
        $this->assertStringContainsString($p->sku, $csv->getContent());
        $this->actingAs($this->manager)->get(route('reports.spoilage.export', ['format' => 'xlsx']))->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $this->actingAs($this->userWithRole(Permissions::ROLE_WAREHOUSE_STAFF))->get(route('reports.spoilage.index'))->assertForbidden();
    }

    public function test_only_loss_reasons_count_samples_and_internal_use_do_not()
    {
        $p = $this->product(2.0);
        $this->stockIn($p, 100);
        $this->writeOff($p, 3, 'DAMAGED');
        $this->writeOff($p, 4, 'SPOILED', 'Fridge failed');
        $this->giveAway($p, 5, 'SAMPLE');
        $this->giveAway($p, 6, 'INTERNAL');
        $this->sale($p, 20, '2.0000');
        $today = BusinessDate::today();

        $r = app(SpoilageReport::class)->build($today, $today);
        $this->assertSame(7, $r['totals']['units']);
        $this->assertSame(14.0, $r['totals']['value']);
        $this->assertSame(35.0, $r['totals']['percent_of_cogs']); // 14 of 40
        $this->assertSame(14.0, array_sum(array_column($r['trend'], 'value')));
        $this->assertEqualsCanonicalizing(['Damaged / broken', 'Spoiled (cold chain / quality)'], array_column($r['groups']['reason'], 'label'));
        // Existing write-offs keep their reason: the ledger is untouched.
        $this->assertSame(4, StockMovement::where('type', 'write_off')->count());

        // The list is a company setting.
        $admin = $this->userWithRole(Permissions::ROLE_ADMIN);
        $this->actingAs($admin)->get(route('admin.settings.edit'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('lossReasons', Setting::DEFAULT_LOSS_REASONS)
            ->where('lossReasonOptions', fn ($o) => ! collect($o)->pluck('code')->intersect(['SAMPLE', 'INTERNAL'])->count()));
        $this->actingAs($admin)->put(route('admin.settings.update'), ['company_name' => 'Co', 'spoilage_loss_reasons' => ['SAMPLE']])
            ->assertSessionHasErrors('spoilage_loss_reasons.0');
        $this->actingAs($admin)->put(route('admin.settings.update'), ['company_name' => 'Co', 'spoilage_loss_reasons' => ['DAMAGED']])
            ->assertSessionHasNoErrors();
        $this->assertSame(['DAMAGED'], Setting::spoilageLossReasons());
        $this->assertSame(6.0, app(SpoilageReport::class)->build($today, $today)['totals']['value']);
    }
}
