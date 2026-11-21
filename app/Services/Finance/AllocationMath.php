<?php

namespace App\Services\Finance;

use Illuminate\Support\Carbon;

/**
 * The arithmetic of Income allocation, in whole cents so totals always equal receipts.
 */
final class AllocationMath
{
    /** GST on an ex-GST amount: 10%, rounded to the cent (half up); nothing on GST-free. */
    public static function gstOn(int $exCents, bool $gstFree, float $rate = 10): int
    {
        return $gstFree ? 0 : (int) round($exCents * $rate / 100, 0, PHP_ROUND_HALF_UP);
    }

    /**
     * The GST inside an inc-GST receipt: its taxable share ÷ 11.
     * $taxableShare is the inc-GST taxable part of the sales it pays for (0 to 1).
     */
    public static function gstInReceipt(int $amountCents, float $taxableShare, float $rate = 10): int
    {
        $share = min(1.0, max(0.0, $taxableShare));

        return (int) round($amountCents * $share * $rate / (100 + $rate), 0, PHP_ROUND_HALF_UP);
    }

    /** Inc-GST taxable share of sales from ex-GST taxable and GST-free figures; null with no sales. */
    public static function taxableShare(int $taxableExCents, int $gstFreeCents, float $rate = 10): ?float
    {
        $taxableInc = $taxableExCents * (1 + $rate / 100);
        $total = $taxableInc + $gstFreeCents;

        return $total > 0 ? $taxableInc / $total : null;
    }

    /**
     * Company income tax provision: rate × net profit estimate, never negative, never more than $available.
     *
     * Profit = sales ex GST − COGS − the OpEx and Owner's Comp transfers. Those transfers are a share
     * ($opexOcShare) of what's left after Tax, so they shrink as the provision grows. Solving
     * T = r × (S − C − s × (A − T)) gives T = r × (S − C − s × A) ÷ (1 − r × s), A = receipts after GST.
     */
    public static function companyTax(int $salesEx, int $cogs, int $available, float $ratePercent, float $opexOcShare): int
    {
        $r = $ratePercent / 100;
        if ($r <= 0 || $available <= 0) {
            return 0;
        }
        $s = min(1.0, max(0.0, $opexOcShare));
        $t = $r * ($salesEx - $cogs - $s * $available) / (1 - $r * $s);

        return (int) min($available, max(0, round($t)));
    }

    /**
     * Tax first, capped at receipts: GST before company tax.
     *
     * @return array{gst: int, company: int, total: int, required: int, capped: bool, short: int}
     */
    public static function tax(int $receipts, int $gst, int $company): array
    {
        $required = $gst + $company;
        $gstPart = min($gst, max(0, $receipts));
        $companyPart = min($company, max(0, $receipts - $gstPart));

        return [
            'gst' => $gstPart,
            'company' => $companyPart,
            'total' => $gstPart + $companyPart,
            'required' => $required,
            'capped' => $required > $receipts,
            'short' => max(0, $required - $receipts),
        ];
    }

    /** Days of $from–$to (inclusive) someone is employed: the overlap with $start to $end (open if null). */
    public static function employedDays(string $from, string $to, string $start, ?string $end): int
    {
        $a = max($from, $start);
        $b = $end === null ? $to : min($to, $end);

        return $a > $b ? 0 : (int) Carbon::parse($a)->diffInDays(Carbon::parse($b)) + 1;
    }

    /**
     * One person's pay for $days days, in cents. Hourly: rate × hours a week × days ÷ 7. Salary
     * (rate a year): rate × days ÷ 365. Super on top of gross at $superPercent. PAYG withholding
     * is an estimate of the part of gross paid to the ATO instead of the employee: inside gross,
     * not added to it.
     *
     * @return array{gross: int, super: int, payg: int, total: int}
     */
    public static function pay(string $payType, float $rate, ?float $hoursPerWeek, float $superPercent, int $days, float $paygPercent = 0): array
    {
        $gross = $payType === 'salary'
            ? (int) round($rate * 100 * $days / 365)
            : (int) round($rate * 100 * (float) $hoursPerWeek * $days / 7);
        $super = (int) round($gross * $superPercent / 100);

        return ['gross' => $gross, 'super' => $super, 'payg' => (int) round($gross * $paygPercent / 100), 'total' => $gross + $super];
    }

    /**
     * Split $cents by weight, rescaled to 100%: each share rounded down to the cent, the
     * remainder to $remainderTo (or the biggest weight if that takes no share). The parts add up to $cents.
     *
     * @param  array<string, float>  $weights  key => weight; zero or less takes nothing
     * @return array<string, int>
     */
    public static function split(int $cents, array $weights, string $remainderTo = 'stock'): array
    {
        $weights = array_filter($weights, fn ($w) => $w > 0);
        $total = array_sum($weights);
        if ($total <= 0 || $cents <= 0) {
            return array_map(fn () => 0, $weights);
        }
        $parts = [];
        foreach ($weights as $key => $w) {
            $parts[$key] = (int) floor($cents * $w / $total);
        }
        $to = array_key_exists($remainderTo, $parts) ? $remainderTo : array_keys($weights, max($weights))[0];
        $parts[$to] += $cents - array_sum($parts);

        return $parts;
    }

    /** @param array<string, float> $weights @return array<string, float> each weight as a % of the split */
    public static function rescaled(array $weights): array
    {
        $weights = array_filter($weights, fn ($w) => $w > 0);
        $total = array_sum($weights);

        return array_map(fn ($w) => $total > 0 ? round($w / $total * 100, 2) : 0.0, $weights);
    }

    /** The allocation day on or after $date; day 31 in a 30-day month is the 30th. @param list<int> $days */
    public static function nextDate(string $date, array $days): string
    {
        $d = Carbon::parse($date)->startOfDay();
        for ($m = 0; $m < 3; $m++) {
            $month = $d->copy()->startOfMonth()->addMonthsNoOverflow($m);
            foreach ($days as $day) {
                $candidate = $month->copy()->day(min($day, $month->daysInMonth));
                if ($candidate->gte($d)) {
                    return $candidate->toDateString();
                }
            }
        }

        return $d->toDateString();
    }

    /** The allocation day before $date. @param list<int> $days */
    public static function previousDate(string $date, array $days): string
    {
        $d = Carbon::parse($date)->startOfDay();
        for ($m = 0; $m < 3; $m++) {
            $month = $d->copy()->startOfMonth()->subMonthsNoOverflow($m);
            foreach (array_reverse($days) as $day) {
                $candidate = $month->copy()->day(min($day, $month->daysInMonth));
                if ($candidate->lt($d)) {
                    return $candidate->toDateString();
                }
            }
        }

        return $d->copy()->subMonth()->toDateString();
    }

    /** One percentage point a quarter toward the target, never past it. */
    public static function stepToward(float $current, float $target, float $step = 1.0): float
    {
        if (abs($target - $current) <= $step) {
            return $target;
        }

        return round($current + ($target > $current ? $step : -$step), 2);
    }

    public static function dollars(int $cents): float
    {
        return round($cents / 100, 2);
    }

    public static function cents(float|string|null $dollars): int
    {
        return (int) round((float) $dollars * 100);
    }
}
