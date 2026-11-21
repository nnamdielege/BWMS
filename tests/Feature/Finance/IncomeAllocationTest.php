<?php

namespace Tests\Feature\Finance;

use App\Authorization\Permissions;
use App\Enums\OrderChannel;
use App\Models\AllocationAccount;
use App\Models\AllocationReceipt;
use App\Models\AllocationRun;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Finance\AllocationMath;
use App\Services\Finance\AllocationSettings;
use App\Services\Finance\BankStatementCsv;
use App\Services\Finance\IncomeAllocator;
use App\Services\Purchasing\PurchaseOrderService;
use App\Services\Purchasing\ReceivingService;
use App\Support\BusinessDate;
use Database\Factories\ProductFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Support\OrderFixtures;
use Tests\Support\StockFixtures;
use Tests\TestCase;

class IncomeAllocationTest extends TestCase
{
    use OrderFixtures, RefreshDatabase, StockFixtures;

    private User $admin;

    private User $manager;

    private Product $milk;   // taxable

    private Product $apples; // GST-free

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedStockReferenceData();
        $this->admin = $this->userWithRole(Permissions::ROLE_ADMIN);
        $this->manager = $this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER);
        $this->milk = $this->product(2.0, ['gst_free' => false]);
        $this->apples = $this->product(1.0, ['gst_free' => true]);
    }

    private function product(float $cost, array $attrs): Product
    {
        $p = Product::factory()->withPack('Carton', 12)->create($attrs);
        $p->forceFill(['avg_cost' => $cost])->save();
        $this->stockIn($p, 1000);

        return $p->fresh();
    }

    private function receipt(float $amount, ?Order $order = null, string $description = 'Customer payment'): AllocationReceipt
    {
        return app(IncomeAllocator::class)->addReceipt($this->admin, ['received_on' => BusinessDate::today(), 'description' => $description,
            'amount' => $amount, 'order_number' => $order?->number]);
    }

    /** The calculation for tomorrow, over the last week. */
    private function calc(array $opening = [], ?string $date = null): array
    {
        $today = BusinessDate::today();
        $date ??= Carbon::parse($today)->addDay()->toDateString();

        return app(IncomeAllocator::class)->calculate($date, Carbon::parse($today)->subDays(6)->toDateString(), $today, $opening);
    }

    private function transfer(array $calc, string $role): float
    {
        return collect($calc['transfers'])->firstWhere('role', $role)['amount'];
    }

    private function settings(array $values): void
    {
        Setting::put($values);
    }

    public function test_seeded_accounts_store_codes_and_weights_and_no_account_numbers()
    {
        $accounts = AllocationAccount::ordered()->keyBy('role')->toBase();
        $this->assertSame(['1010', '1012', '1011', '1013', '1014', '1015', '1016'],
            $accounts->only(['income', 'tax', 'stock', 'owners_comp', 'opex', 'profit', 'vault'])->pluck('xero_code')->values()->all());
        $this->assertSame([73.0, 10.5, 5.0, 0.5, 0.5], $accounts->only(['stock', 'owners_comp', 'opex', 'profit', 'vault'])->pluck('weight')->values()->all());
        $this->assertNull($accounts['tax']->weight);
        $this->assertFalse($accounts['debt_service']->is_active);
        $this->assertSame([10, 25], AllocationSettings::days());
        $this->assertSame(0.0, AllocationSettings::companyTaxRate());
        $this->assertSame('accrual', AllocationSettings::gstBasis());
    }

    public function test_accrual_gst_on_mixed_taxable_and_gst_free_sales_across_channels()
    {
        $this->dispatchedSale($this->manager, $this->milk, 10, null, '5.00');                          // 50.00, GST 5.00
        $this->dispatchedSale($this->manager, $this->milk, 3, null, '3.33', OrderChannel::Portal);     // 9.99, GST 1.00 (0.999 rounded)
        $this->dispatchedSale($this->manager, $this->apples, 4, null, '2.50', OrderChannel::Ordermentum); // 10.00 GST-free
        $this->receipt(1000);

        $c = $this->calc();
        $this->assertSame([59.99, 6.0, 10.0, 69.99, 30.0], [$c['sales']['taxable_ex'], $c['sales']['gst'], $c['sales']['gst_free_ex'], $c['sales']['total_ex'], $c['sales']['cogs']]);
        $ch = collect($c['sales']['channels'])->keyBy('channel');
        $this->assertSame([50.0, 5.0, 0.0], [$ch['manual']['taxable_ex'], $ch['manual']['gst'], $ch['manual']['gst_free_ex']]);
        $this->assertSame([9.99, 1.0, 0.0], [$ch['portal']['taxable_ex'], $ch['portal']['gst'], $ch['portal']['gst_free_ex']]);
        $this->assertSame([0.0, 0.0, 10.0], [$ch['ordermentum']['taxable_ex'], $ch['ordermentum']['gst'], $ch['ordermentum']['gst_free_ex']]);

        // Tax first (6.00), then 994.00 by 73 / 10.5 / 5 / 0.5 / 0.5 rescaled; the 3c remainder goes to Stock.
        $this->assertSame(6.0, $this->transfer($c, 'tax'));
        $this->assertSame([810.76, 116.61, 55.53, 5.55, 5.55], array_map(fn ($r) => $this->transfer($c, $r), ['stock', 'owners_comp', 'opex', 'profit', 'vault']));
        $this->assertSame(1000.0, $c['total']);
        $this->assertSame('PF ALLOC '.strtoupper(Carbon::parse($c['date'])->format('d-M')).' Stock', collect($c['transfers'])->firstWhere('role', 'stock')['reference']);
        $this->assertFalse($c['company_tax']['shown']);
        $this->assertSame(0.0, $c['tax']['company']);
    }

    public function test_sales_outside_the_period_and_undispatched_orders_carry_no_gst()
    {
        $this->travelTo(now()->subDays(20));
        $this->dispatchedSale($this->manager, $this->milk, 10, null, '5.00');
        $this->travelBack();
        $this->manualOrder($this->manager, $this->milk, 5);
        $this->assertSame(0.0, $this->calc()['sales']['gst']);
    }

    public function test_cash_basis_apportions_each_receipt_by_its_order_or_the_period()
    {
        $this->settings([AllocationSettings::GST_BASIS => 'cash']);
        $taxable = $this->dispatchedSale($this->manager, $this->milk, 10, null, '5.00');   // 50 ex, 55 inc
        $free = $this->dispatchedSale($this->manager, $this->apples, 4, null, '2.50');     // 10, GST-free
        $this->receipt(55, $taxable);   // all taxable: 5.00
        $this->receipt(11, $free);      // GST-free: 0
        $this->receipt(100);            // unmatched: 55 / 65 taxable → 100 × 0.84615 ÷ 11 = 7.69

        $c = $this->calc();
        $this->assertSame('cash', $c['gst']['basis']);
        $this->assertSame(12.69, $c['gst']['collected']);
        $this->assertSame(12.69, $c['tax']['gst']);
        $this->assertSame(['matched' => 2, 'unmatched' => 1, 'assumed_fully_taxable' => 0, 'period_share' => 84.6], $c['gst']['cash']);
        $this->assertSame(166.0, $c['total']);
        // Accrual on the same sales sets aside the GST on the sales instead.
        $this->settings([AllocationSettings::GST_BASIS => 'accrual']);
        $this->assertSame(5.0, $this->calc()['tax']['gst']);
    }

    public function test_cash_basis_with_no_sales_treats_an_unmatched_receipt_as_fully_taxable()
    {
        $this->settings([AllocationSettings::GST_BASIS => 'cash']);
        $this->receipt(110);
        $c = $this->calc();
        $this->assertSame(10.0, $c['tax']['gst']);
        $this->assertStringContainsString('fully taxable', implode(' ', $c['warnings']));
    }

    public function test_gst_credits_are_information_unless_net_off_is_ticked()
    {
        $this->dispatchedSale($this->manager, $this->milk, 10, null, '5.00'); // GST 5.00
        $this->receivePo($this->milk, 2);                                      // 2 × $24 ex, 10% = 4.80 credit
        $this->receivePo($this->apples, 1);                                    // GST-free: no credit
        $this->receipt(500);

        $off = $this->calc();
        $this->assertSame([5.0, 4.8, 0.2, 5.0, false], [$off['gst']['collected'], $off['gst']['credits'], $off['gst']['net_estimate'], $off['tax']['gst'], $off['gst']['net_off']]);

        $this->settings([AllocationSettings::NET_OFF_CREDITS => '1']);
        $on = $this->calc();
        $this->assertSame([0.2, true], [$on['tax']['gst'], $on['gst']['net_off']]);
        $this->assertSame(500.0, $on['total']);

        // Credits bigger than GST collected set aside nothing, never a negative transfer.
        $this->receivePo($this->milk, 5);
        $this->assertSame(0.0, $this->calc()['tax']['gst']);
    }

    public function test_company_tax_is_off_at_zero_and_a_never_negative_share_of_profit_above_zero()
    {
        $this->dispatchedSale($this->manager, $this->milk, 100, null, '5.00'); // 500 ex, COGS 200, GST 50
        $this->receipt(550);
        $this->assertSame([false, 0.0], [$this->calc()['company_tax']['shown'], $this->calc()['tax']['company']]);

        $this->settings([AllocationSettings::COMPANY_TAX_RATE => '25']);
        $c = $this->calc();
        $this->assertTrue($c['company_tax']['shown']);
        // 25% of (500 − 200 − the OpEx and Owner's Comp transfers, which shrink as the provision grows).
        $this->assertSame(55.77, $c['tax']['company']);
        $this->assertEqualsWithDelta($c['company_tax']['profit'] * 0.25, $c['tax']['company'], 0.01);
        $this->assertSame(round($this->transfer($c, 'opex') + $this->transfer($c, 'owners_comp'), 2), $c['company_tax']['opex_oc']);
        $this->assertSame(round(50 + 55.77, 2), $this->transfer($c, 'tax'));
        $this->assertSame(550.0, $c['total']);
    }

    public function test_company_tax_is_never_negative_on_a_loss()
    {
        $this->settings([AllocationSettings::COMPANY_TAX_RATE => '25']);
        $this->milk->forceFill(['avg_cost' => 6])->save();
        $this->dispatchedSale($this->manager, $this->milk->fresh(), 100, null, '5.00'); // a loss
        $this->receipt(550);
        $c = $this->calc();
        $this->assertSame(0.0, $c['tax']['company']);
        $this->assertSame(50.0, $this->transfer($c, 'tax'));
    }

    public function test_tax_more_than_receipts_is_capped_with_a_warning()
    {
        $this->settings([AllocationSettings::COMPANY_TAX_RATE => '25']);
        $this->dispatchedSale($this->manager, $this->milk, 100, null, '5.00'); // GST 50
        $this->receipt(30);
        $c = $this->calc();
        $this->assertSame([30.0, 30.0, 0.0, true], [$c['tax']['total'], $c['tax']['gst'], $c['tax']['company'], $c['tax']['capped']]);
        $this->assertSame(20.0, $c['tax']['short']);
        $this->assertSame([0.0, 0.0], [$this->transfer($c, 'stock'), $this->transfer($c, 'vault')]);
        $this->assertSame(30.0, $c['total']);
        $this->assertStringContainsString('more than the receipts', implode(' ', $c['warnings']));
    }

    public function test_split_is_rescaled_and_always_adds_up_to_receipts()
    {
        $w = ['stock' => 73, 'owners_comp' => 10.5, 'opex' => 5, 'profit' => 0.5, 'vault' => 0.5];
        $this->assertSame(['stock' => 81.56, 'owners_comp' => 11.73, 'opex' => 5.59, 'profit' => 0.56, 'vault' => 0.56], AllocationMath::rescaled($w));
        foreach ([1, 7, 99, 100001, 123456789, 33333] as $cents) {
            $parts = AllocationMath::split($cents, $w);
            $this->assertSame($cents, array_sum($parts), "split of {$cents}");
            $this->assertGreaterThanOrEqual(0, min($parts));
        }
        // Weights that don't add to 100 are rescaled: 1:1 splits evenly, the odd cent to Stock.
        $this->assertSame(['stock' => 51, 'opex' => 50], AllocationMath::split(101, ['stock' => 3, 'opex' => 3]));
        // Debt Service on: it takes its share of what's left after Tax.
        AllocationAccount::where('role', 'debt_service')->first()->forceFill(['is_active' => true, 'weight' => 10.5])->save();
        $this->receipt(1000.03);
        $c = $this->calc();
        $this->assertSame(100.0, collect($c['transfers'])->sum('percent'));
        $this->assertSame(1000.03, $c['total']);
        $this->assertSame(105.0, $this->transfer($c, 'debt_service'));
        $this->assertStringContainsString('Supplier credit → ATO → Business loan → Equipment finance', collect($c['advice'])->firstWhere('role', 'debt_service')['lines'][0]);
    }

    public function test_allocation_days_and_default_period()
    {
        $this->assertSame('2026-11-25', AllocationMath::nextDate('2026-11-21', [10, 25]));
        $this->assertSame('2026-11-25', AllocationMath::nextDate('2026-11-25', [10, 25]));
        $this->assertSame('2026-12-10', AllocationMath::nextDate('2026-11-26', [10, 25]));
        $this->assertSame('2026-11-30', AllocationMath::nextDate('2026-11-26', [31]));
        $this->assertSame('2026-11-10', AllocationMath::previousDate('2026-11-25', [10, 25]));
        $this->assertSame('2026-10-25', AllocationMath::previousDate('2026-11-10', [10, 25]));
        $this->assertSame(['date' => '2026-11-25', 'from' => '2026-11-10', 'to' => '2026-11-24'], app(IncomeAllocator::class)->defaults('2026-11-25'));
    }

    public function test_bank_csv_import_takes_credits_only_and_never_imports_the_same_row_twice()
    {
        $order = $this->dispatchedSale($this->manager, $this->milk, 2, null, '5.00');
        $csv = "Date,Description,Amount,Balance\n"
            ."20/11/2026,Payment from Cafe {$order->number},110.00,1000\n"
            ."20/11/2026,Rent,-500.00,500\n"
            ."21/11/2026,Card settlement,250.50,750.50\n"
            ."21/11/2026,Card settlement,250.50,1001\n"; // a second, identical payment that day: both count
        $import = fn (string $content) => $this->actingAs($this->admin)->post(route('finance.allocation.receipts.import'),
            ['file' => UploadedFile::fake()->createWithContent('statement.csv', $content)]);

        $import($csv)->assertRedirect()->assertSessionHas('success');
        $this->assertSame(3, AllocationReceipt::count());
        $this->assertSame('611.00', number_format((float) AllocationReceipt::sum('amount'), 2, '.', ''));
        $this->assertSame($order->id, AllocationReceipt::where('description', 'like', 'Payment from Cafe%')->value('order_id'));

        $import($csv)->assertSessionHas('error');
        $this->assertSame(3, AllocationReceipt::count());

        // An overlapping statement adds only the new row.
        $import($csv."22/11/2026,EFT Bistro,99.99,1100\n")->assertSessionHas('success');
        $this->assertSame(4, AllocationReceipt::count());
        $this->assertSame(1, AuditLog::where('event', 'income_receipts_imported')->where('new_values->added', 3)->count());
    }

    public function test_bank_csv_formats()
    {
        // CommBank: no header, date, amount, description, balance.
        $cba = BankStatementCsv::parse([['05/11/2026', '+1,234.56', 'Direct credit ACME', '+5000.00'], ['06/11/2026', '-20.00', 'Fee', '+4980.00']]);
        $this->assertSame([['received_on' => '2026-11-05', 'description' => 'Direct credit ACME', 'cents' => 123456]],
            array_map(fn ($c) => array_diff_key($c, ['fingerprint' => 0]), $cba['credits']));
        $this->assertSame(1, $cba['debits']);
        // Credit and Debit columns.
        $split = BankStatementCsv::parse([['Transaction Date', 'Narrative', 'Debit', 'Credit'], ['2026-11-07', 'Deposit', '', '75.00'], ['2026-11-07', 'Withdrawal', '10.00', '']]);
        $this->assertSame([7500], array_column($split['credits'], 'cents'));
        $this->assertSame(1, $split['debits']);
        $this->assertSame([-1200, 1200, 1200, null], [BankStatementCsv::amount('(12.00)'), BankStatementCsv::amount('12.00 CR'), BankStatementCsv::amount('$12'), BankStatementCsv::amount('abc')]);
        $this->assertSame(['2026-11-05', '2026-11-05', null], [BankStatementCsv::date('5 Nov 2026'), BankStatementCsv::date('05-Nov-26'), BankStatementCsv::date('31/02/2026')]);
    }

    public function test_vault_target_advice()
    {
        $s = ['fixed_costs_weekly' => 1000.0, 'vault_weeks_min' => 4.0, 'vault_weeks_max' => 8.0];
        $reached = IncomeAllocator::vaultAdvice(500, 900000, $s);
        $this->assertSame('warn', $reached['level']);
        $this->assertStringContainsString('Target reached', $reached['lines'][1]);
        $this->assertStringContainsString('stop feeding the Vault, redirect $5.00', $reached['lines'][1]);
        $this->assertStringContainsString('target reached', IncomeAllocator::vaultAdvice(50000, 780000, $s)['lines'][1]);
        $this->assertStringContainsString('within the target', IncomeAllocator::vaultAdvice(500, 500000, $s)['lines'][1]);
        $this->assertStringContainsString('short of 4 weeks', IncomeAllocator::vaultAdvice(500, 100000, $s)['lines'][1]);
        $this->assertSame([4000.0, 8000.0], [IncomeAllocator::vaultAdvice(500, null, $s)['target_low'], IncomeAllocator::vaultAdvice(500, null, $s)['target_high']]);
        $this->assertSame('info', IncomeAllocator::vaultAdvice(500, null, ['fixed_costs_weekly' => null] + $s)['level']);

        // Through the page, with the Vault balance entered.
        $this->settings([AllocationSettings::FIXED_COSTS_WEEKLY => '1000']);
        $this->receipt(1000);
        $vault = collect($this->calc(['vault' => '9000'])['advice'])->firstWhere('role', 'vault');
        $this->assertStringContainsString('Target reached', implode(' ', $vault['lines']));
    }

    public function test_stock_advice_flags_the_overdraft_and_supplier_payments_due()
    {
        $this->dispatchedSale($this->manager, $this->milk, 100, null, '5.00'); // COGS 40% of sales
        $po = $this->receivePo($this->milk, 50); // $1,320 inc GST
        $po->forceFill(['payment_due_date' => BusinessDate::today()])->save();
        $this->receipt(1100);

        $stock = collect($this->calc(['stock' => '-200'])['advice'])->firstWhere('role', 'stock');
        $text = implode(' ', $stock['lines']);
        $this->assertSame('warn', $stock['level']);
        $this->assertStringContainsString('overdrawn by $200.00', $text);
        $this->assertStringContainsString('1 supplier payment of $1,320.00', $text);
        $this->assertStringContainsString('Cost of goods ran at 40%', $text);
    }

    public function test_owners_comp_and_quarter_end_profit_advice_and_review()
    {
        $this->settings([AllocationSettings::DIRECTOR_SALARIES => '2800']);
        AllocationAccount::where('role', 'profit')->first()->forceFill(['target_weight' => 5])->save();
        $this->receipt(1000);
        $allocator = app(IncomeAllocator::class);

        // 25 Dec: the next day (10 Jan) is in a new quarter.
        $c = $allocator->calculate('2026-12-25', '2026-12-10', '2026-12-24', ['profit' => '100']);
        $oc = collect($c['advice'])->firstWhere('role', 'owners_comp');
        $this->assertSame('warn', $oc['level']);
        $this->assertStringContainsString('need about $3,000.00 for these 15 days', $oc['lines'][0]);
        $profit = collect($c['advice'])->firstWhere('role', 'profit');
        $base = 100 + $this->transfer($c, 'profit');
        $this->assertStringContainsString('distribute 50% ($'.number_format(floor($base * 100 / 2) / 100, 2).')', $profit['lines'][0]);
        $review = collect($c['review']['rows'])->keyBy('role');
        $this->assertSame([0.5, 5.0, 1.5], [$review['profit']['current'], $review['profit']['target'], $review['profit']['suggested']]);
        $this->assertNull($review['stock']['suggested']);

        // Mid-quarter: no profit distribution, no review.
        $mid = $allocator->calculate('2026-11-25', '2026-11-10', '2026-11-24');
        $this->assertNull(collect($mid['advice'])->firstWhere('role', 'profit'));
        $this->assertNull($mid['review']);
    }

    public function test_mark_done_records_the_run_once_and_takes_the_receipts()
    {
        $this->dispatchedSale($this->manager, $this->milk, 10, null, '5.00');
        $this->receipt(600);
        $this->receipt(400.01);
        $today = BusinessDate::today();
        $params = ['date' => Carbon::parse($today)->addDay()->toDateString(), 'from' => Carbon::parse($today)->subDays(6)->toDateString(), 'to' => $today];

        // The receipts changed since the admin looked: refused.
        $this->actingAs($this->admin)->post(route('finance.allocation.done'), $params + ['receipts_total' => 600])->assertSessionHasErrors('receipts');
        $this->assertSame(0, AllocationRun::count());

        $this->actingAs($this->admin)->post(route('finance.allocation.done'), $params + ['receipts_total' => 1000.01])->assertRedirect(route('finance.allocation.index'));
        $run = AllocationRun::with('lines')->sole();
        $this->assertSame('1000.01', (string) $run->receipts_total);
        $this->assertSame(1000.01, round($run->lines->sum(fn ($l) => (float) $l->amount), 2));
        $this->assertSame(5.0, (float) $run->lines->firstWhere('role', 'tax')->amount);
        $this->assertSame(0, AllocationReceipt::waiting()->count());
        $audit = AuditLog::where('event', 'income_allocation_done')->sole();
        $this->assertSame($this->admin->id, $audit->user_id);
        $this->assertSame(1000.01, $audit->new_values['receipts']);

        // One run per allocation day.
        $this->receipt(50);
        $this->actingAs($this->admin)->post(route('finance.allocation.done'), $params + ['receipts_total' => 50])->assertSessionHasErrors('date');

        // History shows each account's total; the CSV is for internet banking.
        $this->actingAs($this->admin)->get(route('finance.allocation.index'))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('finance/IncomeAllocation')
                ->where('history.runs.0.receipts', 1000.01)->where('history.totals.tax', 5)->where('calc.receipts.total', 50));
        $csv = $this->actingAs($this->admin)->get(route('finance.allocation.runs.export', $run))->assertOk()->getContent();
        $this->assertStringContainsString('PF2 Stock', $csv);
        $this->assertStringContainsString('PF ALLOC', $csv);
    }

    public function test_settings_are_validated_and_audited()
    {
        $page = $this->actingAs($this->admin)->get(route('finance.allocation.settings'))->assertOk();
        $accounts = collect($page->viewData('page')['props']['accounts'])->map(fn ($a) => array_intersect_key($a, array_flip(['id', 'name', 'xero_code', 'purpose', 'weight', 'target_weight', 'institution', 'last4', 'is_active'])));
        $payload = fn (Collection $accounts) => ['accounts' => $accounts->values()->all(), 'days' => [10, 25], 'gst_basis' => 'cash', 'net_off_credits' => true,
            'company_tax_rate' => 25, 'fixed_costs_weekly' => 1500, 'vault_weeks_min' => 4, 'vault_weeks_max' => 8, 'director_salaries_fortnight' => 4000,
            'debt_priority' => ['ATO', 'Supplier credit']];

        // A full account number is refused: last 4 digits only.
        $bad = $accounts->map(fn ($a) => $a['name'] === 'PF2 Stock' ? ['last4' => '06201234'] + $a : $a);
        $this->actingAs($this->admin)->put(route('finance.allocation.settings.update'), $payload($bad))->assertSessionHasErrors('accounts.2.last4');

        $good = $accounts->map(fn ($a) => $a['name'] === 'PF2 Stock' ? ['last4' => '1234', 'weight' => 70, 'institution' => 'CBA'] + $a : $a);
        $this->actingAs($this->admin)->put(route('finance.allocation.settings.update'), $payload($good))->assertSessionHasNoErrors();
        $stock = AllocationAccount::where('role', 'stock')->first();
        $this->assertSame(['1234', 70.0, 'CBA'], [$stock->last4, $stock->weight, $stock->institution]);
        $this->assertSame(['cash', true, 25.0, ['ATO', 'Supplier credit']],
            [AllocationSettings::gstBasis(), AllocationSettings::netOffCredits(), AllocationSettings::companyTaxRate(), AllocationSettings::debtPriority()]);
        $this->assertTrue(AuditLog::where('auditable_type', $stock->getMorphClass())->where('auditable_id', $stock->id)->where('event', 'updated')->exists());
        $this->assertTrue(AuditLog::where('auditable_type', (new Setting)->getMorphClass())->where('auditable_id', AllocationSettings::GST_BASIS)->exists());

        // No weights at all is refused.
        $none = $accounts->map(fn ($a) => ['weight' => 0] + $a);
        $this->actingAs($this->admin)->put(route('finance.allocation.settings.update'), $payload($none))->assertSessionHasErrors('accounts');
    }

    public function test_receipts_entered_by_hand_are_audited_and_only_waiting_ones_can_be_removed()
    {
        $this->actingAs($this->admin)->post(route('finance.allocation.receipts.store'), ['received_on' => BusinessDate::today(), 'description' => 'EFT', 'amount' => '0'])
            ->assertSessionHasErrors('amount');
        $this->actingAs($this->admin)->post(route('finance.allocation.receipts.store'), ['received_on' => BusinessDate::today(), 'description' => 'EFT', 'amount' => '10', 'order_number' => 'SO-1999-00001'])
            ->assertSessionHasErrors('order_number');
        $this->actingAs($this->admin)->post(route('finance.allocation.receipts.store'), ['received_on' => BusinessDate::today(), 'description' => 'EFT', 'amount' => '125.50'])
            ->assertSessionHasNoErrors();
        $r = AllocationReceipt::sole();
        $this->assertTrue(AuditLog::where('auditable_type', $r->getMorphClass())->where('event', 'created')->exists());

        $run = AllocationRun::create(['allocation_date' => '2026-01-10', 'period_from' => '2025-12-25', 'period_to' => '2026-01-09', 'receipts_total' => 1,
            'gst_basis' => 'accrual', 'snapshot' => [], 'done_at' => now()]);
        $taken = AllocationReceipt::create(['received_on' => '2026-01-05', 'description' => 'Old', 'amount' => 1, 'source' => 'manual', 'allocation_run_id' => $run->id]);
        $this->actingAs($this->admin)->delete(route('finance.allocation.receipts.destroy', $taken))->assertSessionHasErrors('receipt');
        $this->actingAs($this->admin)->delete(route('finance.allocation.receipts.destroy', $r))->assertSessionHasNoErrors();
        $this->assertSame(1, AllocationReceipt::count());
    }

    public function test_admins_only()
    {
        foreach (Permissions::roleMatrix() as $role => $permissions) {
            $this->assertSame($role === Permissions::ROLE_ADMIN, in_array(Permissions::FINANCE_ALLOCATE, $permissions, true), $role);
        }
        foreach ([Permissions::ROLE_WAREHOUSE_MANAGER, Permissions::ROLE_WAREHOUSE_STAFF, Permissions::ROLE_PURCHASING, Permissions::ROLE_READ_ONLY] as $role) {
            $user = $this->userWithRole($role);
            $this->actingAs($user)->get(route('finance.allocation.index'))->assertForbidden();
            $this->actingAs($user)->get(route('finance.allocation.settings'))->assertForbidden();
            $this->actingAs($user)->get(route('finance.allocation.export'))->assertForbidden();
            $this->actingAs($user)->post(route('finance.allocation.receipts.store'), ['received_on' => BusinessDate::today(), 'description' => 'x', 'amount' => 1])->assertForbidden();
            $this->actingAs($user)->post(route('finance.allocation.done'), ['receipts_total' => 1])->assertForbidden();
            $this->actingAs($user)->get(route('dashboard'))->assertInertia(fn (AssertableInertia $p) => $p->missing('widgets.allocation'));
        }

        $this->receipt(250);
        $this->actingAs($this->admin)->get(route('dashboard'))->assertInertia(fn (AssertableInertia $p) => $p
            ->where('widgets.allocation.waiting_total', 250)->where('widgets.allocation.waiting_count', 1)
            ->where('widgets.allocation.next_date', AllocationMath::nextDate(BusinessDate::today(), [10, 25])));
        $this->actingAs($this->admin)->get(route('finance.allocation.export'))->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    /** A received PO of $cartons cartons of 12 at $24 ex GST a carton, at the product's own GST rate. */
    private function receivePo(Product $product, int $cartons): PurchaseOrder
    {
        $s = app(PurchaseOrderService::class);
        $po = $s->saveDraft(null, [
            'supplier_id' => Supplier::factory()->create()->id, 'site_id' => $this->site()->id, 'order_date' => BusinessDate::today(),
            'lines' => [['product_id' => $product->id, 'product_unit_id' => ProductFactory::unitOf($product, 12)->id, 'qty_ordered' => $cartons, 'unit_cost' => '24.00']],
        ], $this->manager);
        $po = $s->markSent($s->approve($po, $this->manager, true, 'test'), $this->manager);
        app(ReceivingService::class)->receive($po, $this->manager, [['purchase_order_line_id' => $po->lines->first()->id, 'qty_accepted_base' => $cartons * 12]], (string) Str::uuid());

        return $po->fresh();
    }
}
