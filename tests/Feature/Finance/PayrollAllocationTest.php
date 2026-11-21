<?php

namespace Tests\Feature\Finance;

use App\Authorization\Permissions;
use App\Models\AllocationAccount;
use App\Models\AllocationRun;
use App\Models\AllocationStaff;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Services\Finance\AllocationMath;
use App\Services\Finance\AllocationSettings;
use App\Services\Finance\IncomeAllocator;
use App\Support\BusinessDate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\OrderFixtures;
use Tests\Support\StockFixtures;
use Tests\TestCase;

class PayrollAllocationTest extends TestCase
{
    use OrderFixtures, RefreshDatabase, StockFixtures;

    private User $admin;

    private User $manager;

    private Product $milk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedStockReferenceData();
        $this->admin = $this->userWithRole(Permissions::ROLE_ADMIN);
        $this->manager = $this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER);
        $this->milk = Product::factory()->withPack('Carton', 12)->create(['gst_free' => false]);
        $this->milk->forceFill(['avg_cost' => 2.0])->save();
        $this->stockIn($this->milk, 1000);
    }

    private function periodStart(): string
    {
        return Carbon::parse(BusinessDate::today())->subDays(14)->toDateString(); // 15 days to today
    }

    /** Over the 15 days to today, for tomorrow. */
    private function calc(): array
    {
        $today = BusinessDate::today();

        return app(IncomeAllocator::class)->calculate(Carbon::parse($today)->addDay()->toDateString(), $this->periodStart(), $today);
    }

    private function staff(array $attrs = []): AllocationStaff
    {
        $this->actingAs($this->admin)->post(route('finance.allocation.staff.store'), $attrs + [
            'name' => 'Sam', 'role' => 'Picker', 'pay_type' => 'hourly', 'rate' => 30, 'hours_per_week' => 38, 'super_percent' => 12,
            'start_date' => '2025-01-01', 'is_active' => true,
        ])->assertSessionHasNoErrors();

        return AllocationStaff::latest('id')->first();
    }

    private function receipt(float $amount): void
    {
        app(IncomeAllocator::class)->addReceipt($this->admin, ['received_on' => BusinessDate::today(), 'description' => 'Payment', 'amount' => $amount]);
    }

    private function transfer(array $c, string $role): ?float
    {
        return collect($c['transfers'])->firstWhere('role', $role)['amount'] ?? null;
    }

    public function test_hourly_and_salary_pay_with_super_and_payg()
    {
        // Hourly: $30 × 38 h a week × 14 days ÷ 7 = $2,280; super 12% = $273.60.
        $this->assertSame(['gross' => 228000, 'super' => 27360, 'payg' => 0, 'total' => 255360], AllocationMath::pay('hourly', 30, 38, 12, 14));
        // Salary: $73,000 a year × 15 days ÷ 365 = $3,000; super 11.5% = $345; PAYG 20% of gross is inside it.
        $this->assertSame(['gross' => 300000, 'super' => 34500, 'payg' => 60000, 'total' => 334500], AllocationMath::pay('salary', 73000, null, 11.5, 15, 20));
        $this->assertSame(0, AllocationMath::pay('hourly', 30, 38, 12, 0)['total']);
    }

    public function test_start_and_end_dates_mid_period()
    {
        $d = fn (?string $start, ?string $end) => AllocationMath::employedDays('2026-11-10', '2026-11-24', $start, $end);
        $this->assertSame(15, $d('2026-01-01', null));
        $this->assertSame(5, $d('2026-11-20', null));          // started mid-period
        $this->assertSame(3, $d('2026-01-01', '2026-11-12'));  // left mid-period
        $this->assertSame(1, $d('2026-11-15', '2026-11-15'));
        $this->assertSame(0, $d('2026-01-01', '2026-11-09'));  // left before
        $this->assertSame(0, $d('2026-11-25', null));          // starts after

        // Through the allocation: only the employed days are paid.
        $joiner = $this->staff(['name' => 'Ava', 'start_date' => Carbon::parse(BusinessDate::today())->subDays(6)->toDateString()]); // 7 days
        $leaver = $this->staff(['name' => 'Ben', 'pay_type' => 'salary', 'rate' => 36500, 'hours_per_week' => null,
            'end_date' => Carbon::parse($this->periodStart())->addDays(9)->toDateString()]);                                                  // 10 days
        $this->staff(['name' => 'Cal', 'end_date' => Carbon::parse($this->periodStart())->subDay()->toDateString()]);                        // gone
        $this->receipt(10000);
        $people = collect($this->calc()['payroll']['people'])->keyBy('name');
        $this->assertSame(['Ava', 'Ben'], $people->keys()->sort()->values()->all());
        $this->assertSame([7, 1140.0, 136.8], [$people['Ava']['days'], $people['Ava']['gross'], $people['Ava']['super']]); // 30 × 38 × 7 ÷ 7
        $this->assertSame([10, 1000.0, 120.0], [$people['Ben']['days'], $people['Ben']['gross'], $people['Ben']['super']]); // 36,500 × 10 ÷ 365
        $this->assertSame(2396.8, $this->calc()['payroll']['required']);
        $this->assertNotNull($joiner->id);
        $this->assertNotNull($leaver->id);
    }

    public function test_payroll_account_is_off_until_a_staff_member_is_active()
    {
        $account = fn () => AllocationAccount::where('role', 'payroll')->sole();
        $this->assertSame(['PF8 Payroll', false], [$account()->name, $account()->is_active]);
        $this->receipt(1000);
        $this->assertFalse($this->calc()['payroll']['on']);
        $this->assertNull($this->transfer($this->calc(), 'payroll'));

        $sam = $this->staff();
        $this->assertTrue($account()->is_active);
        $this->actingAs($this->admin)->put(route('finance.allocation.staff.update', $sam), ['is_active' => false] + $sam->only(['name', 'role', 'pay_type', 'rate', 'hours_per_week', 'super_percent'])
            + ['start_date' => '2025-01-01'])->assertSessionHasNoErrors();
        $this->assertFalse($account()->is_active);

        // The Xero code is yours to set; saving settings doesn't switch Payroll on or off.
        $this->staff();
        $account()->forceFill(['xero_code' => '1017'])->save();
        $this->assertTrue($account()->is_active);
    }

    public function test_tax_then_payroll_then_the_weighted_split()
    {
        $this->dispatchedSale($this->manager, $this->milk, 100, null, '5.00'); // GST 50.00
        $this->staff();                                                        // 30 × 38 × 15 ÷ 7 = 2,442.86 + 12% 293.14 = 2,736.00
        $this->receipt(10000);

        $c = $this->calc();
        $this->assertSame(['tax', 'payroll', 'stock', 'owners_comp', 'opex', 'profit', 'vault'], array_column($c['transfers'], 'role'));
        $this->assertSame([50.0, 2736.0], [$this->transfer($c, 'tax'), $this->transfer($c, 'payroll')]);
        $this->assertSame([2442.86, 293.14, 2736.0], [$c['payroll']['gross'], $c['payroll']['super'], $c['payroll']['required']]);
        // Stock, Owner's Comp, OpEx, Profit and Vault rescale on what's left: 10,000 − 50 − 2,736 = 7,214.
        $split = AllocationMath::split(721400, ['stock' => 73, 'owners_comp' => 10.5, 'opex' => 5, 'profit' => 0.5, 'vault' => 0.5]);
        foreach ($split as $role => $cents) {
            $this->assertSame($cents / 100, $this->transfer($c, $role), $role);
        }
        $this->assertSame(10000.0, $c['total']);
        $this->assertNull(collect($c['transfers'])->firstWhere('role', 'payroll')['percent']);

        // Advice: Payroll's share of receipts.
        $advice = collect($c['advice'])->firstWhere('role', 'payroll');
        $this->assertStringContainsString('Payroll is 27.4% of receipts', $advice['lines'][0]);

        // Mark done records the Payroll line.
        $this->actingAs($this->admin)->post(route('finance.allocation.done'), ['date' => $c['date'], 'from' => $c['from'], 'to' => $c['to'], 'receipts_total' => 10000])
            ->assertSessionHasNoErrors();
        $this->assertSame('2736.00', (string) AllocationRun::sole()->lines()->where('role', 'payroll')->value('amount'));
    }

    public function test_tax_and_payroll_more_than_receipts_warns_with_the_shortfall()
    {
        $this->dispatchedSale($this->manager, $this->milk, 100, null, '5.00'); // GST 50
        $this->staff();                                                        // needs 2,736
        $this->receipt(2000);

        $c = $this->calc();
        $this->assertSame([50.0, 1950.0, 786.0], [$this->transfer($c, 'tax'), $c['payroll']['amount'], $c['payroll']['short']]);
        $this->assertSame([0.0, 0.0], [$this->transfer($c, 'stock'), $this->transfer($c, 'vault')]);
        $this->assertSame(2000.0, $c['total']);
        $this->assertStringContainsString('Tax and Payroll ($2,786.00) are more than the receipts ($2,000.00): short by $786.00', implode(' ', $c['warnings']));
    }

    public function test_payg_line_is_optional_and_does_not_change_the_transfer()
    {
        $this->staff();
        $this->receipt(10000);
        $off = $this->calc();
        $this->assertFalse($off['payroll']['payg_shown']);

        Setting::put([AllocationSettings::PAYG_RATE => '20']);
        $on = $this->calc();
        $this->assertTrue($on['payroll']['payg_shown']);
        $this->assertSame(488.57, $on['payroll']['payg']); // 20% of 2,442.86
        $this->assertSame($this->transfer($off, 'payroll'), $this->transfer($on, 'payroll'));
    }

    public function test_warns_when_payroll_pushes_stock_below_cost_of_goods()
    {
        $this->dispatchedSale($this->manager, $this->milk, 100, null, '5.00'); // COGS 40% of sales
        $this->receipt(1000);                                                  // needs about $363.64 for stock
        $this->staff(['rate' => 25, 'hours_per_week' => 10]);                  // 535.71 + 64.29 = $600

        $c = $this->calc();
        $this->assertSame(600.0, $this->transfer($c, 'payroll'));
        $advice = collect($c['advice'])->firstWhere('role', 'payroll');
        $this->assertSame('warn', $advice['level']);
        $this->assertStringContainsString('Payroll pushes Stock below the period\'s cost of goods', $advice['lines'][1]);
        $this->assertSame('warn', collect($c['advice'])->firstWhere('role', 'stock')['level']);
    }

    public function test_company_tax_profit_estimate_takes_off_payroll()
    {
        Setting::put([AllocationSettings::COMPANY_TAX_RATE => '25']);
        $this->dispatchedSale($this->manager, $this->milk, 1000, null, '10.00'); // 10,000 ex, COGS 2,000, GST 1,000
        $this->staff();
        $this->receipt(11000);

        $c = $this->calc();
        $this->assertSame(2736.0, $c['company_tax']['payroll']);
        $this->assertEqualsWithDelta(10000 - 2000 - 2736 - $c['company_tax']['opex_oc'], $c['company_tax']['profit'], 0.001);
        $this->assertGreaterThan(0, $c['tax']['company']);
        $this->assertEqualsWithDelta($c['company_tax']['profit'] * 0.25, $c['tax']['company'], 0.01);
        $this->assertSame(11000.0, $c['total']);
    }

    public function test_staff_are_validated_audited_and_admins_only()
    {
        $this->actingAs($this->admin)->post(route('finance.allocation.staff.store'), ['name' => 'X', 'pay_type' => 'hourly', 'rate' => 30,
            'super_percent' => 12, 'start_date' => '2026-01-01'])->assertSessionHasErrors('hours_per_week');
        $this->actingAs($this->admin)->post(route('finance.allocation.staff.store'), ['name' => 'X', 'pay_type' => 'salary', 'rate' => 50000,
            'super_percent' => 12, 'start_date' => '2026-02-01', 'end_date' => '2026-01-01'])->assertSessionHasErrors('end_date');

        $sam = $this->staff();
        $this->assertSame(12.0, $sam->super_percent);
        $this->assertTrue(AuditLog::where('auditable_type', $sam->getMorphClass())->where('auditable_id', $sam->id)->where('event', 'created')->exists());
        $this->actingAs($this->admin)->put(route('finance.allocation.staff.update', $sam), ['rate' => 32] + $sam->only(['name', 'role', 'pay_type', 'hours_per_week', 'super_percent'])
            + ['start_date' => '2025-01-01', 'is_active' => true])->assertSessionHasNoErrors();
        $updated = AuditLog::where('auditable_type', $sam->getMorphClass())->where('auditable_id', $sam->id)->where('event', 'updated')->sole();
        $this->assertEquals(['rate' => 32], $updated->new_values);
        $this->actingAs($this->admin)->delete(route('finance.allocation.staff.destroy', $sam))->assertSessionHasNoErrors();
        $this->assertTrue(AuditLog::where('auditable_type', $sam->getMorphClass())->where('auditable_id', $sam->id)->where('event', 'deleted')->exists());

        $other = $this->staff();
        foreach ([Permissions::ROLE_WAREHOUSE_MANAGER, Permissions::ROLE_WAREHOUSE_STAFF, Permissions::ROLE_PURCHASING, Permissions::ROLE_READ_ONLY] as $role) {
            $user = $this->userWithRole($role);
            $this->actingAs($user)->post(route('finance.allocation.staff.store'), ['name' => 'Y'])->assertForbidden();
            $this->actingAs($user)->put(route('finance.allocation.staff.update', $other), ['name' => 'Y'])->assertForbidden();
            $this->actingAs($user)->delete(route('finance.allocation.staff.destroy', $other))->assertForbidden();
            $this->actingAs($user)->get(route('finance.allocation.settings'))->assertForbidden();
        }
        $this->actingAs($this->admin)->get(route('finance.allocation.settings'))->assertOk()
            ->assertInertia(fn ($p) => $p->where('staff.0.name', 'Sam')->where('staff.0.super_percent', 12));
    }
}
