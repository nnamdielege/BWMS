<?php

namespace App\Services\Finance;

use App\Enums\PurchaseOrderStatus;
use App\Models\AllocationAccount;
use App\Models\AllocationReceipt;
use App\Models\AllocationRun;
use App\Models\AllocationRunLine;
use App\Models\Order;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Support\Audit;
use App\Support\BusinessDate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Finance → Income allocation (Profit First). Advises how to split the receipts waiting in
 * Income across the bank accounts on an allocation day. It never moves money: "Mark transfers
 * done" only records what the admin did in internet banking.
 *
 * Order: Tax first (GST, then company tax), capped at receipts; what's left is split by the
 * weights of Stock, Owner's Comp, OpEx, Profit, Vault (and Debt Service when on), rescaled to
 * 100%, in cents, the rounding remainder to Stock. Advice notes compare the split with the
 * business's own figures and never change it.
 *
 * The period of an allocation day runs from the day after the last run's period (or the
 * previous allocation day) to the day before it: the 25th covers the 10th to the 24th.
 */
class IncomeAllocator
{
    public function __construct(private SalesGst $sales, private OrderMatcher $matcher) {}

    /** @return array{date: string, from: string, to: string} the allocation day and its period */
    public function defaults(?string $date = null): array
    {
        $days = AllocationSettings::days();
        $date = $date !== null && BusinessDate::parse($date) !== null ? BusinessDate::parse($date) : AllocationMath::nextDate(BusinessDate::today(), $days);
        $last = AllocationRun::whereDate('allocation_date', '<', $date)->orderByDesc('allocation_date')->first();
        $from = $last ? $last->period_to->copy()->addDay()->toDateString() : AllocationMath::previousDate($date, $days);
        $to = Carbon::parse($date)->subDay()->toDateString();

        return ['date' => $date, 'from' => min($from, $to), 'to' => $to];
    }

    public function nextAllocationDate(): string
    {
        return AllocationMath::nextDate(BusinessDate::today(), AllocationSettings::days());
    }

    /** @param array<string, float|string|null> $opening role => opening balance in dollars (blank = unknown) */
    public function calculate(string $date, string $from, string $to, array $opening = []): array
    {
        $settings = AllocationSettings::all();
        $accounts = AllocationAccount::ordered()->keyBy('role');
        $receipts = AllocationReceipt::waiting()->with('order:id,number')->orderBy('received_on')->orderBy('id')->get();
        $opening = $this->opening($opening);
        $received = $receipts->sum(fn ($r) => AllocationMath::cents($r->amount));
        $nextDate = AllocationMath::nextDate(Carbon::parse($date)->addDay()->toDateString(), $settings['days']);

        $sales = $this->sales->period($from, $to);
        $credits = $this->sales->credits($from, $to);
        $periodShare = AllocationMath::taxableShare($sales['taxable_ex'], $sales['gst_free_ex'], Product::GST_RATE);
        [$collected, $cash] = $settings['gst_basis'] === 'cash' ? $this->cashGst($receipts, $periodShare) : [$sales['gst'], null];
        $gstSetAside = $settings['net_off_credits'] ? max(0, $collected - $credits) : $collected;

        $weights = $accounts->filter->takesShare()->map(fn (AllocationAccount $a) => (float) $a->weight)->all();
        $weightTotal = array_sum($weights);
        $opexOcShare = $weightTotal > 0 ? (($weights[AllocationAccount::OPEX] ?? 0) + ($weights[AllocationAccount::OWNERS_COMP] ?? 0)) / $weightTotal : 0;
        $company = AllocationMath::companyTax($sales['total_ex'], $sales['cogs'], max(0, $received - min($gstSetAside, $received)), $settings['company_tax_rate'], $opexOcShare);
        $tax = AllocationMath::tax($received, $gstSetAside, $company);
        $parts = AllocationMath::split($received - $tax['total'], $weights, AllocationAccount::STOCK);
        $percent = AllocationMath::rescaled($weights);

        $ref = 'PF ALLOC '.strtoupper(Carbon::parse($date)->format('d-M')).' ';
        $transfers = [];
        foreach ($accounts as $role => $a) {
            if ($role === AllocationAccount::TAX) {
                $cents = $tax['total'];
            } elseif ($a->takesShare()) {
                $cents = $parts[$role] ?? 0;
            } else {
                continue;
            }
            $transfers[] = [
                'role' => $role, 'account_id' => $a->id, 'name' => $a->name, 'xero_code' => $a->xero_code,
                'institution' => $a->institution, 'last4' => $a->last4, 'weight' => $role === AllocationAccount::TAX ? null : $a->weight,
                'percent' => $percent[$role] ?? null, 'cents' => $cents, 'amount' => AllocationMath::dollars($cents),
                'reference' => mb_substr($ref.$a->shortName(), 0, 60),
            ];
        }
        $opexOc = ($parts[AllocationAccount::OPEX] ?? 0) + ($parts[AllocationAccount::OWNERS_COMP] ?? 0);

        $calc = [
            'date' => $date, 'from' => $from, 'to' => $to, 'next_date' => $nextDate,
            'settings' => $settings,
            'source' => ['name' => $accounts[AllocationAccount::INCOME]->name ?? 'Income', 'xero_code' => $accounts[AllocationAccount::INCOME]->xero_code ?? null,
                'institution' => $accounts[AllocationAccount::INCOME]->institution ?? null, 'last4' => $accounts[AllocationAccount::INCOME]->last4 ?? null],
            'receipts' => ['count' => $receipts->count(), 'cents' => $received, 'total' => AllocationMath::dollars($received), 'ids' => $receipts->pluck('id')->all()],
            'sales' => array_merge(self::toDollars($sales, ['taxable_ex', 'gst', 'gst_free_ex', 'total_ex', 'cogs']), [
                'channels' => array_map(fn ($c) => self::toDollars($c, ['taxable_ex', 'gst', 'gst_free_ex']), $sales['channels']),
                'cogs_percent' => $sales['total_ex'] > 0 ? round($sales['cogs'] / $sales['total_ex'] * 100, 1) : null,
            ]),
            'gst' => [
                'basis' => $settings['gst_basis'],
                'collected' => AllocationMath::dollars($collected),
                'credits' => AllocationMath::dollars($credits),
                'net_estimate' => AllocationMath::dollars($collected - $credits),
                'net_off' => $settings['net_off_credits'],
                'set_aside' => AllocationMath::dollars($gstSetAside),
                'cash' => $cash,
            ],
            'company_tax' => [
                'rate' => $settings['company_tax_rate'],
                'shown' => $settings['company_tax_rate'] > 0,
                'sales_ex' => AllocationMath::dollars($sales['total_ex']),
                'cogs' => AllocationMath::dollars($sales['cogs']),
                'opex_oc' => AllocationMath::dollars($opexOc),
                'profit' => AllocationMath::dollars($sales['total_ex'] - $sales['cogs'] - $opexOc),
                'amount' => AllocationMath::dollars($company),
            ],
            'tax' => [
                'gst' => AllocationMath::dollars($tax['gst']), 'company' => AllocationMath::dollars($tax['company']),
                'total' => AllocationMath::dollars($tax['total']), 'required' => AllocationMath::dollars($tax['required']),
                'capped' => $tax['capped'], 'short' => AllocationMath::dollars($tax['short']),
            ],
            'transfers' => $transfers,
            'total' => AllocationMath::dollars(array_sum(array_column($transfers, 'cents'))),
            'opening' => array_map(fn ($c) => $c === null ? null : AllocationMath::dollars($c), $opening),
        ];
        $calc['warnings'] = $this->warnings($calc, $sales, $cash);
        $calc['advice'] = $this->advice($calc, $accounts, $parts, $opening, $sales, $periodShare);
        $calc['review'] = $this->review($calc, $accounts, $percent);

        return $calc;
    }

    /** "Mark transfers done": records the run as calculated now. Refuses if the receipts changed since the admin looked. */
    public function record(User $user, string $date, string $from, string $to, array $opening, int $expectedCents): AllocationRun
    {
        return DB::transaction(function () use ($user, $date, $from, $to, $opening, $expectedCents) {
            AllocationReceipt::waiting()->lockForUpdate()->get(['id']);
            if (AllocationRun::whereDate('allocation_date', $date)->exists()) {
                throw ValidationException::withMessages(['date' => 'The allocation for '.Carbon::parse($date)->format('j M Y').' is already done.']);
            }
            if (AllocationRun::whereDate('period_from', '<=', $to)->whereDate('period_to', '>=', $from)->exists()) {
                throw ValidationException::withMessages(['from' => 'That period overlaps one already allocated.']);
            }
            $calc = $this->calculate($date, $from, $to, $opening);
            if ($calc['receipts']['cents'] <= 0) {
                throw ValidationException::withMessages(['receipts' => 'There are no receipts waiting in Income.']);
            }
            if ($calc['receipts']['cents'] !== $expectedCents) {
                throw ValidationException::withMessages(['receipts' => 'The receipts changed since you calculated. Check the transfers again.']);
            }

            $run = AllocationRun::create([
                'allocation_date' => $date, 'period_from' => $from, 'period_to' => $to,
                'receipts_total' => $calc['receipts']['total'], 'gst_basis' => $calc['gst']['basis'],
                'snapshot' => $calc, 'done_by' => $user->id, 'done_at' => now(),
            ]);
            foreach ($calc['transfers'] as $t) {
                AllocationRunLine::create([
                    'allocation_run_id' => $run->id, 'allocation_account_id' => $t['account_id'], 'role' => $t['role'],
                    'name' => $t['name'], 'xero_code' => $t['xero_code'], 'amount' => $t['amount'], 'reference' => $t['reference'],
                ]);
            }
            AllocationReceipt::whereIn('id', $calc['receipts']['ids'])->update(['allocation_run_id' => $run->id]);

            Audit::record('income_allocation_done', $run, new: [
                'allocation_date' => $date, 'period' => "{$from} to {$to}", 'receipts' => $calc['receipts']['total'],
                'receipt_count' => $calc['receipts']['count'], 'gst_basis' => $calc['gst']['basis'],
                'tax_gst' => $calc['tax']['gst'], 'tax_company' => $calc['tax']['company'],
                'transfers' => collect($calc['transfers'])->mapWithKeys(fn ($t) => [$t['name'] => $t['amount']])->all(),
            ], userId: $user->id);

            return $run;
        });
    }

    /** @param array{received_on: string, description: string, amount: string|float, order_number?: ?string} $data */
    public function addReceipt(User $user, array $data): AllocationReceipt
    {
        $order = null;
        $ref = trim((string) ($data['order_number'] ?? ''));
        if ($ref !== '') {
            // The BWMS number, the portal's ORD-… ref, or Ordermentum's invoice or order number.
            $ids = $this->matcher->byReference($ref);
            if (count($ids) !== 1) {
                throw ValidationException::withMessages(['order_number' => $ids === []
                    ? "No order {$ref}."
                    : "{$ref} matches more than one order: use the SO number."]);
            }
            $order = Order::find($ids[0]);
        }

        return AllocationReceipt::create([
            'received_on' => $data['received_on'], 'description' => $data['description'], 'amount' => round((float) $data['amount'], 2),
            'source' => 'manual', 'order_id' => $order?->id, 'created_by' => $user->id,
        ]);
    }

    /**
     * Credits from a bank-statement CSV, skipping any already imported.
     *
     * @param  list<list<string>>  $rows
     * @return array{added: int, total: float, duplicates: int, debits: int, invalid: int}
     */
    public function import(User $user, array $rows, string $filename): array
    {
        $parsed = BankStatementCsv::parse($rows);
        $known = AllocationReceipt::whereIn('fingerprint', array_column($parsed['credits'], 'fingerprint'))->pluck('fingerprint')->flip();
        $added = 0;
        $total = 0;
        Audit::withoutModelEvents(function () use ($parsed, $known, $user, &$added, &$total) {
            foreach ($parsed['credits'] as $c) {
                if ($known->has($c['fingerprint'])) {
                    continue;
                }
                AllocationReceipt::create([
                    'received_on' => $c['received_on'], 'description' => $c['description'], 'amount' => AllocationMath::dollars($c['cents']),
                    'source' => 'csv', 'fingerprint' => $c['fingerprint'], 'order_id' => $this->matcher->inDescription($c['description']), 'created_by' => $user->id,
                ]);
                $added++;
                $total += $c['cents'];
            }
        });
        $result = ['added' => $added, 'total' => AllocationMath::dollars($total), 'duplicates' => count($parsed['credits']) - $added,
            'debits' => $parsed['debits'], 'invalid' => $parsed['invalid']];
        Audit::record('income_receipts_imported', null, new: $result + ['file' => $filename], userId: $user->id);

        return $result;
    }

    /** Runs, latest first, with each account's amount; and each account's total over them. */
    public function history(): array
    {
        $runs = AllocationRun::with(['lines', 'doneBy:id,name'])->orderByDesc('allocation_date')->get();

        return [
            'runs' => $runs->map(fn (AllocationRun $r) => [
                'id' => $r->id, 'date' => $r->allocation_date->toDateString(), 'from' => $r->period_from->toDateString(), 'to' => $r->period_to->toDateString(),
                'receipts' => (float) $r->receipts_total, 'gst_basis' => $r->gst_basis, 'done_by' => $r->doneBy?->name, 'done_at' => $r->done_at->toIso8601String(),
                'lines' => $r->lines->mapWithKeys(fn ($l) => [$l->role => (float) $l->amount])->all(),
            ])->all(),
            'totals' => $runs->flatMap->lines->groupBy('role')->map(fn (Collection $g) => round($g->sum(fn ($l) => (float) $l->amount), 2))->all(),
        ];
    }

    /** CSV rows for internet banking: one transfer per account that gets money. */
    public static function csvRows(array $calc): array
    {
        $rows = [['Date', 'From account', 'From Xero code', 'To account', 'To Xero code', 'To bank', 'To account (last 4)', 'Amount', 'Reference']];
        foreach ($calc['transfers'] as $t) {
            if ($t['cents'] <= 0) {
                continue;
            }
            $rows[] = [$calc['date'], $calc['source']['name'], $calc['source']['xero_code'], $t['name'], $t['xero_code'], $t['institution'],
                $t['last4'], number_format($t['amount'], 2, '.', ''), $t['reference']];
        }

        return $rows;
    }

    /** @return array{0: int, 1: array} GST in the receipts (cents), and how it was worked out */
    private function cashGst(Collection $receipts, ?float $periodShare): array
    {
        $gst = 0;
        $matched = 0;
        $assumedFull = 0;
        $shares = [];
        foreach ($receipts as $r) {
            $share = null;
            if ($r->order_id !== null) {
                $shares[$r->order_id] ??= $this->sales->orderTaxableShare(Order::find($r->order_id)) ?? false;
                $share = $shares[$r->order_id] === false ? null : $shares[$r->order_id];
                $matched += $share !== null ? 1 : 0;
            }
            $share ??= $periodShare;
            if ($share === null) {
                $share = 1.0;
                $assumedFull++;
            }
            $gst += AllocationMath::gstInReceipt(AllocationMath::cents($r->amount), $share, Product::GST_RATE);
        }

        return [$gst, ['matched' => $matched, 'unmatched' => $receipts->count() - $matched, 'assumed_fully_taxable' => $assumedFull,
            'period_share' => $periodShare === null ? null : round($periodShare * 100, 1)]];
    }

    private function warnings(array $calc, array $sales, ?array $cash): array
    {
        $w = [];
        if ($calc['receipts']['count'] === 0) {
            $w[] = 'No receipts are waiting in Income. Add them, or import a bank statement.';
        }
        if ($calc['tax']['capped']) {
            $w[] = 'Tax due ('.self::m($calc['tax']['required']).') is more than the receipts ('.self::m($calc['receipts']['total']).'). '
                .'The whole of the receipts goes to Tax and nothing to the other accounts; '.self::m($calc['tax']['short']).' is still to set aside.';
        }
        if ($calc['gst']['basis'] === 'accrual' && $sales['lines'] === 0) {
            $w[] = 'No sales were dispatched in the period, so there is no GST to set aside on the accrual basis. Check the period dates.';
        }
        if ($sales['estimated'] > 0) {
            $w[] = "{$sales['estimated']} dispatched ".($sales['estimated'] === 1 ? 'line has' : 'lines have').' no price on the order; the default price was used.';
        }
        if ($cash !== null && $cash['assumed_fully_taxable'] > 0) {
            $w[] = "{$cash['assumed_fully_taxable']} ".($cash['assumed_fully_taxable'] === 1 ? 'receipt is' : 'receipts are').' treated as fully taxable (1/11 GST): no matched order and no sales in the period to apportion by.';
        }

        return $w;
    }

    /**
     * Notes on each account. Never changes the split.
     *
     * @param  array<string, int>  $parts  cents per split role
     * @param  array<string, ?int>  $opening  cents per role
     */
    private function advice(array $calc, Collection $accounts, array $parts, array $opening, array $sales, ?float $periodShare): array
    {
        $out = [];
        $received = $calc['receipts']['cents'];
        $s = $calc['settings'];

        // Stock: cost of goods, supplier payments due before the next allocation, the overdraft.
        if (isset($parts[AllocationAccount::STOCK])) {
            $stock = $parts[AllocationAccount::STOCK];
            $lines = [];
            $level = 'ok';
            if ($sales['total_ex'] > 0 && $received > 0) {
                $cogsShare = $sales['cogs'] / $sales['total_ex'];
                $receiptsEx = $received - AllocationMath::gstInReceipt($received, $periodShare ?? 1.0, Product::GST_RATE);
                $need = (int) round($receiptsEx * $cogsShare);
                $lines[] = 'Stock gets '.self::m($stock / 100).' ('.round($stock / $received * 100, 1).'% of receipts). Cost of goods ran at '
                    .round($cogsShare * 100, 1).'% of sales ex GST in the period: about '.self::m($need / 100).' of these receipts.';
                if ($stock < $need) {
                    $level = 'warn';
                    $lines[] = 'Stock is short of cost of goods by '.self::m(($need - $stock) / 100).'.';
                }
            }
            $due = PurchaseOrder::whereIn('status', PurchaseOrderStatus::values(PurchaseOrderStatus::PAYABLE))->whereNull('paid_at')
                ->whereNotNull('payment_due_date')->whereDate('payment_due_date', '<', $calc['next_date']);
            $dueCents = AllocationMath::cents((clone $due)->sum('total'));
            $dueCount = (clone $due)->count();
            if ($dueCount > 0) {
                $have = $stock + ($opening[AllocationAccount::STOCK] ?? 0);
                $lines[] = "{$dueCount} supplier ".($dueCount === 1 ? 'payment' : 'payments').' of '.self::m($dueCents / 100).' (inc GST) fall due before the next allocation on '
                    .Carbon::parse($calc['next_date'])->format('j M').($opening[AllocationAccount::STOCK] === null ? ' (enter the Stock balance to check it against what is there).' : '.');
                if ($dueCents > $have) {
                    $level = 'warn';
                    $lines[] = 'Stock'.($opening[AllocationAccount::STOCK] === null ? ' (this transfer alone)' : ' (balance plus this transfer)').' is short of them by '.self::m(($dueCents - $have) / 100).'.';
                }
            } else {
                $lines[] = 'No supplier payments fall due before the next allocation on '.Carbon::parse($calc['next_date'])->format('j M').'.';
            }
            if (($opening[AllocationAccount::STOCK] ?? 0) < 0) {
                $level = 'warn';
                $over = -$opening[AllocationAccount::STOCK];
                $lines[] = 'Stock is overdrawn by '.self::m($over / 100).'. Clear the overdraft first: '.self::m(min($over, $stock) / 100)
                    .' of this transfer goes to it'.($stock > $over ? ', leaving '.self::m(($stock - $over) / 100).' for suppliers.' : ', and it still leaves '.self::m(($over - $stock) / 100).' overdrawn.');
            }
            $out[] = ['role' => AllocationAccount::STOCK, 'title' => $accounts[AllocationAccount::STOCK]->name, 'level' => $level, 'lines' => $lines];
        }

        // Owner's Comp: against director salaries for the days in the period.
        if (isset($parts[AllocationAccount::OWNERS_COMP])) {
            $oc = $parts[AllocationAccount::OWNERS_COMP];
            if ($s['director_salaries_fortnight'] === null) {
                $out[] = ['role' => AllocationAccount::OWNERS_COMP, 'title' => $accounts[AllocationAccount::OWNERS_COMP]->name, 'level' => 'info',
                    'lines' => ["Set director salaries a fortnight in settings to compare them with Owner's Comp."]];
            } else {
                $days = (int) Carbon::parse($calc['from'])->diffInDays(Carbon::parse($calc['to'])) + 1;
                $need = (int) round(AllocationMath::cents($s['director_salaries_fortnight']) * $days / 14);
                $lines = ["Owner's Comp gets ".self::m($oc / 100).'. Director salaries of '.self::m($s['director_salaries_fortnight']).' a fortnight need about '.self::m($need / 100)." for these {$days} days."];
                if ($oc < $need) {
                    $lines[] = 'Short by '.self::m(($need - $oc) / 100).': pay less from it, or review its weight at the quarter.';
                }
                $out[] = ['role' => AllocationAccount::OWNERS_COMP, 'title' => $accounts[AllocationAccount::OWNERS_COMP]->name, 'level' => $oc < $need ? 'warn' : 'ok', 'lines' => $lines];
            }
        }

        // Vault: 4–8 weeks of fixed costs.
        if ($accounts->has(AllocationAccount::VAULT)) {
            $out[] = ['role' => AllocationAccount::VAULT, 'title' => $accounts[AllocationAccount::VAULT]->name] + self::vaultAdvice($parts[AllocationAccount::VAULT] ?? 0, $opening[AllocationAccount::VAULT] ?? null, $s);
        }

        // Profit: at the end of a quarter, distribute half and keep half.
        if (isset($parts[AllocationAccount::PROFIT]) && self::quarterEnds($calc['date'], $calc['next_date'])) {
            $profit = $parts[AllocationAccount::PROFIT];
            if ($opening[AllocationAccount::PROFIT] !== null) {
                $base = $opening[AllocationAccount::PROFIT] + $profit;
                $basis = 'the Profit balance after this transfer';
            } else {
                $start = Carbon::parse($calc['date'])->firstOfQuarter()->toDateString();
                $base = $profit + AllocationMath::cents(AllocationRunLine::where('role', AllocationAccount::PROFIT)
                    ->whereHas('run', fn ($q) => $q->whereDate('allocation_date', '>=', $start)->whereDate('allocation_date', '<', $calc['date']))->sum('amount'));
                $basis = "this quarter's Profit transfers including this one (enter the Profit balance to use that instead)";
            }
            $base = max(0, $base);
            $distribute = intdiv($base, 2);
            $out[] = ['role' => AllocationAccount::PROFIT, 'title' => $accounts[AllocationAccount::PROFIT]->name, 'level' => 'info', 'lines' => [
                'Quarter end: distribute 50% ('.self::m($distribute / 100).') and keep 50% ('.self::m(($base - $distribute) / 100).") in Profit, from {$basis}: ".self::m($base / 100).'.',
            ]];
        }

        if (isset($parts[AllocationAccount::DEBT_SERVICE])) {
            $out[] = ['role' => AllocationAccount::DEBT_SERVICE, 'title' => $accounts[AllocationAccount::DEBT_SERVICE]->name, 'level' => 'info', 'lines' => [
                'Debt Service gets '.self::m($parts[AllocationAccount::DEBT_SERVICE] / 100).'. Pay debts in this order: '.implode(' → ', $s['debt_priority']).'.',
            ]];
        }

        return $out;
    }

    /** @return array{level: string, lines: list<string>, target_low: ?float, target_high: ?float} */
    public static function vaultAdvice(int $transfer, ?int $opening, array $s): array
    {
        if ($s['fixed_costs_weekly'] === null) {
            return ['level' => 'info', 'lines' => ['Set fixed costs a week in settings for a Vault target.'], 'target_low' => null, 'target_high' => null];
        }
        $weekly = AllocationMath::cents($s['fixed_costs_weekly']);
        $low = (int) round($weekly * $s['vault_weeks_min']);
        $high = (int) round($weekly * $s['vault_weeks_max']);
        $target = 'Target: '.self::weeks($s['vault_weeks_min']).'–'.self::weeks($s['vault_weeks_max']).' weeks of fixed costs, '.self::m($low / 100).' to '.self::m($high / 100).'.';
        $out = ['target_low' => AllocationMath::dollars($low), 'target_high' => AllocationMath::dollars($high)];
        if ($opening === null) {
            return $out + ['level' => 'info', 'lines' => [$target, 'Enter the Vault balance to check it against the target.']];
        }
        $after = $opening + $transfer;
        $weeksHeld = $weekly > 0 ? round($opening / $weekly, 1) : null;
        if ($high > 0 && $opening >= $high) {
            return $out + ['level' => 'warn', 'lines' => [$target, 'Target reached ('.self::m($opening / 100).($weeksHeld !== null ? ", {$weeksHeld} weeks" : '').') — stop feeding the Vault, redirect '
                .self::m($transfer / 100).' this time (to Debt Service or Profit, say) and set its weight to 0 in settings.']];
        }
        if ($after >= $high) {
            return $out + ['level' => 'ok', 'lines' => [$target, 'This transfer brings the Vault to '.self::m($after / 100).': target reached. Stop feeding it after this one.']];
        }
        if ($opening >= $low) {
            return $out + ['level' => 'ok', 'lines' => [$target, 'The Vault holds '.self::m($opening / 100).($weeksHeld !== null ? " ({$weeksHeld} weeks)" : '').': within the target. Keep feeding it to '.self::m($high / 100).'.']];
        }

        return $out + ['level' => 'info', 'lines' => [$target, 'The Vault holds '.self::m($opening / 100).': '.self::m(($low - $after) / 100).' short of '.self::weeks($s['vault_weeks_min']).' weeks after this transfer.']];
    }

    /** At a quarter end (the next allocation day is in a new quarter): each weight against its target, moving 1 point toward it. */
    private function review(array $calc, Collection $accounts, array $percent): ?array
    {
        if (! self::quarterEnds($calc['date'], $calc['next_date'])) {
            return null;
        }
        $rows = $accounts->filter(fn (AllocationAccount $a) => $a->isSplit() && ($a->is_active || $a->target_weight !== null))
            ->map(fn (AllocationAccount $a) => [
                'role' => $a->role, 'name' => $a->name, 'current' => (float) $a->weight, 'percent' => $percent[$a->role] ?? 0.0, 'target' => $a->target_weight,
                'suggested' => $a->target_weight === null ? null : AllocationMath::stepToward((float) $a->weight, (float) $a->target_weight),
            ])->values()->all();

        return ['quarter' => 'Q'.Carbon::parse($calc['date'])->quarter.' '.Carbon::parse($calc['date'])->year, 'rows' => $rows];
    }

    public static function quarterEnds(string $date, string $nextDate): bool
    {
        $a = Carbon::parse($date);
        $b = Carbon::parse($nextDate);

        return $a->quarter !== $b->quarter || $a->year !== $b->year;
    }

    /** @return array<string, ?int> role => cents (null = not given) */
    private function opening(array $opening): array
    {
        $out = [];
        foreach (AllocationAccount::SPLIT_ROLES as $role) {
            $v = $opening[$role] ?? null;
            $out[$role] = $v === null || trim((string) $v) === '' || ! is_numeric($v) ? null : AllocationMath::cents($v);
        }

        return $out;
    }

    private static function toDollars(array $values, array $keys): array
    {
        foreach ($keys as $k) {
            $values[$k] = AllocationMath::dollars($values[$k]);
        }

        return $values;
    }

    private static function weeks(float $w): string
    {
        return rtrim(rtrim(number_format($w, 1), '0'), '.');
    }

    private static function m(float $dollars): string
    {
        return ($dollars < 0 ? '−' : '').'$'.number_format(abs($dollars), 2);
    }
}
