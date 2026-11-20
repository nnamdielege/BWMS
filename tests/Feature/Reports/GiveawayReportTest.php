<?php

namespace Tests\Feature\Reports;

use App\Authorization\Permissions;
use App\Models\Customer;
use App\Models\Giveaway;
use App\Models\Product;
use App\Models\Setting;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Orders\OrderService;
use App\Services\Reports\Insights\GiveawayReport;
use App\Services\Reports\Insights\InsightFilters;
use App\Services\Stock\AdjustmentService;
use App\Services\Stock\Exceptions\InvalidMovement;
use App\Services\Stock\GiveawayService;
use App\Support\BusinessDate;
use Database\Factories\ProductFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Support\StockFixtures;
use Tests\TestCase;

class GiveawayReportTest extends TestCase
{
    use RefreshDatabase, StockFixtures;

    private User $manager;

    private Product $milk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedStockReferenceData();
        $this->manager = $this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER);
        $this->milk = Product::factory()->withPack('Carton', 12)->create();
        $this->milk->forceFill(['avg_cost' => 1.5])->save();
        $this->stockIn($this->milk, 1000);
    }

    private function payload(array $extra = []): array
    {
        return $extra + [
            'client_token' => (string) Str::uuid(), 'product_id' => $this->milk->id, 'product_unit_id' => ProductFactory::unitOf($this->milk, 12)->id,
            'packs' => 2, 'location_id' => $this->bin()->id, 'reason_code_id' => $this->reason('SAMPLE')->id,
            'customer_id' => Customer::factory()->create()->id, 'purpose' => 'Tasting for the new menu', 'approved_by' => $this->manager->id,
        ];
    }

    private function sample(Customer $c, int $daysAgo, string $reason = 'SAMPLE'): void
    {
        $this->travelTo(now()->subDays($daysAgo));
        app(GiveawayService::class)->record($this->manager, $this->milk->fresh(), null, 2, $this->bin(), null, $this->reason($reason), $c, null,
            'Try it', $this->manager, (string) Str::uuid());
        $this->travelBack();
    }

    private function orderOn(Customer $c, int $daysAgo, bool $cancel = false): void
    {
        $this->travelTo(now()->subDays($daysAgo));
        $order = app(OrderService::class)->createManual($this->manager, ['customer_id' => $c->id, 'site_id' => $this->site()->id,
            'lines' => [['product_id' => $this->milk->id, 'product_unit_id' => ProductFactory::unitOf($this->milk, 12)->id, 'qty' => 1]]], true);
        if ($cancel) {
            app(OrderService::class)->cancel($order, $this->manager, 'Changed mind');
        }
        $this->travelBack();
    }

    private function report(int $days = 90): array
    {
        $to = BusinessDate::today();

        return app(GiveawayReport::class)->build(new InsightFilters(Carbon::parse($to)->subDays($days - 1)->toDateString(), $to));
    }

    public function test_give_away_needs_recipient_purpose_and_an_approver_and_is_recorded_in_packs()
    {
        $page = route('giveaways.index');
        $this->actingAs($this->manager)->from($page)->post(route('giveaways.store'), $this->payload(['customer_id' => null]))->assertSessionHasErrors('customer_id');
        $this->actingAs($this->manager)->from($page)->post(route('giveaways.store'), $this->payload(['purpose' => '']))->assertSessionHasErrors('purpose');
        $this->actingAs($this->manager)->from($page)->post(route('giveaways.store'), $this->payload(['approved_by' => null]))->assertSessionHasErrors('approved_by');
        $staff = $this->userWithRole(Permissions::ROLE_WAREHOUSE_STAFF);
        $this->actingAs($this->manager)->from($page)->post(route('giveaways.store'), $this->payload(['approved_by' => $staff->id]))->assertSessionHasErrors('packs');
        $this->actingAs($this->manager)->from($page)->post(route('giveaways.store'), $this->payload(['reason_code_id' => $this->reason('DAMAGED')->id]))->assertSessionHasErrors('reason_code_id');
        $this->assertSame(0, Giveaway::count());

        $payload = $this->payload(['customer_id' => null, 'recipient_name' => 'Jo (staff)', 'reason_code_id' => $this->reason('STAFF')->id]);
        $this->actingAs($this->manager)->post(route('giveaways.store'), $payload)->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post(route('giveaways.store'), $payload)->assertSessionHasNoErrors(); // double submit

        $g = Giveaway::sole();
        $this->assertSame([24, 'Jo (staff)', '36.00', $this->manager->id], [$g->qty_base, $g->recipient(), $g->value, $g->approved_by]);
        $m = StockMovement::where('type', 'write_off')->sole();
        $this->assertSame([-24, 'STAFF', 'giveaway', '1.5000'], [$m->qty, $m->reasonCode->code, $m->source_type, $m->unit_cost]);
        $this->assertStringContainsString('Jo (staff)', $m->note);

        $this->actingAs($staff)->get($page)->assertForbidden();
        $this->actingAs($staff)->post(route('giveaways.store'), $payload)->assertForbidden();
    }

    public function test_give_away_reasons_are_refused_anywhere_else_and_hidden_from_other_forms()
    {
        foreach (['SAMPLE', 'GIFT', 'FAVOUR', 'STAFF', 'INTERNAL'] as $code) {
            try {
                app(AdjustmentService::class)->adjust($this->manager, $this->milk, $this->bin(), -1, $this->reason($code), 'note', (string) Str::uuid());
                $this->fail("{$code} was accepted as a plain write-off");
            } catch (InvalidMovement $e) {
                $this->assertStringContainsString('Give away', $e->getMessage());
            }
        }
        $this->actingAs($this->manager)->get(route('stock.show', $this->milk))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('reasons', fn ($r) => collect($r)->pluck('code')->intersect(['SAMPLE', 'GIFT', 'STAFF'])->isEmpty()));
        $this->actingAs($this->manager)->get(route('giveaways.index', ['product_id' => $this->milk->id]))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('stock/GiveAway')
                ->where('reasons', fn ($r) => collect($r)->pluck('code')->all() === ['SAMPLE', 'GIFT', 'FAVOUR', 'STAFF', 'INTERNAL'])
                ->where('product.slots.0.on_hand', 1000));
    }

    public function test_sample_conversion_within_30_and_60_days()
    {
        [$a, $b, $c, $d, $e] = Customer::factory()->count(5)->create()->all();
        $this->sample($a, 40);
        $this->orderOn($a, 30);           // 10 days after: yes, yes
        $this->sample($b, 70);
        $this->orderOn($b, 25);           // 45 days after: no, yes
        $this->sample($c, 10);            // nothing yet: pending, pending
        $this->sample($d, 35);            // 35 days, nothing: no, pending
        $this->sample($e, 40);
        $this->orderOn($e, 35, cancel: true); // a cancelled order doesn't count: no, pending
        $this->sample($a, 5, 'GIFT');     // not a sample

        $r = $this->report();
        $byCustomer = collect($r['samples'])->keyBy('customer_id');
        $this->assertSame(['yes', 'yes', 10], [$byCustomer[$a->id]['within_30'], $byCustomer[$a->id]['within_60'], $byCustomer[$a->id]['days_to_order']]);
        $this->assertSame(['no', 'yes'], [$byCustomer[$b->id]['within_30'], $byCustomer[$b->id]['within_60']]);
        $this->assertSame(['pending', 'pending'], [$byCustomer[$c->id]['within_30'], $byCustomer[$c->id]['within_60']]);
        $this->assertSame(['no', 'pending'], [$byCustomer[$d->id]['within_30'], $byCustomer[$d->id]['within_60']]);
        $this->assertSame(['no', 'pending'], [$byCustomer[$e->id]['within_30'], $byCustomer[$e->id]['within_60']]);

        $t = $r['totals'];
        $this->assertSame([5, 1, 4, 25.0, 2, 2, 100.0], [$t['samples_to_customers'], $t['converted_30'], $t['decided_30'], $t['conversion_30'], $t['converted_60'], $t['decided_60'], $t['conversion_60']]);
        $this->assertSame([6, 12, 18.0], [$t['count'], $t['units'], $t['value']]);
        $reasons = collect($r['groups']['reason'])->keyBy('label');
        $this->assertSame([5, 15.0, 25.0], [$reasons['Sample / tasting']['count'], $reasons['Sample / tasting']['value'], $reasons['Sample / tasting']['conversion_30']]);
        $this->assertSame(1, $reasons['Gift']['count']);
        $this->assertSame(2, collect($r['groups']['recipient'])->firstWhere('key', "c{$a->id}")['count']);
        $this->assertSame(6, $r['groups']['person'][0]['count']);

        $this->assertSame('pending', GiveawayReport::outcome(null, 30, 29));
        $this->assertSame('no', GiveawayReport::outcome(null, 30, 30));
        $this->assertSame('yes', GiveawayReport::outcome(30.0, 30, 31));
        $this->assertSame('no', GiveawayReport::outcome(31.0, 30, 31));
    }

    public function test_monthly_budget_spend_and_page()
    {
        $this->actingAs($this->manager)->put(route('giveaways.budget'), ['budget' => 5])->assertSessionHasNoErrors();
        $this->assertSame(5.0, Setting::giveawayBudget());
        $this->sample(Customer::factory()->create(), 0);
        $this->sample(Customer::factory()->create(), 0);

        $month = collect($this->report(1)['budget']['months'])->last();
        $this->assertSame([6.0, 5.0, true], [$month['spent'], $month['budget'], $month['over']]);
        $this->actingAs($this->manager)->get(route('giveaways.index'))->assertInertia(fn (AssertableInertia $p) => $p->where('month.spent', 6)->where('month.budget', 5)->has('recent', 2));

        $this->actingAs($this->manager)->put(route('giveaways.budget'), ['budget' => null])->assertSessionHasNoErrors();
        $this->assertNull(Setting::giveawayBudget());

        $this->actingAs($this->manager)->get(route('reports.giveaways.index', ['group' => 'product']))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('reports/insights/Giveaways')->where('report.totals.count', 2));
        foreach (['csv', 'xlsx'] as $format) {
            $this->actingAs($this->manager)->get(route('reports.giveaways.export', ['format' => $format]))->assertOk();
        }
        $this->actingAs($this->userWithRole(Permissions::ROLE_WAREHOUSE_STAFF))->get(route('reports.giveaways.index'))->assertForbidden();
        $this->actingAs($this->userWithRole(Permissions::ROLE_PURCHASING))->put(route('giveaways.budget'), ['budget' => 5])->assertForbidden();
    }
}
