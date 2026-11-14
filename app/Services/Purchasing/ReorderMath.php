<?php

namespace App\Services\Purchasing;

use Illuminate\Support\Carbon;

/**
 * The arithmetic behind reorder suggestions, on its own so it can be tested on its own.
 * Quantities are base units; demand is base units per day.
 */
final class ReorderMath
{
    /** Days of history looked at: 8 weeks. */
    public const WINDOW_DAYS = 56;

    /**
     * Weighted average daily demand over the last 8 weeks: each week's rate (sold ÷ days
     * counted), the most recent week weighing 8 and the oldest 1. A null day is a stock-out
     * (nothing on hand all day) and is left out, so it doesn't drag demand down.
     *
     * @param  list<int|null>  $daily  WINDOW_DAYS entries, oldest first
     * @return array{avg: float, std: float, days: int, weeks: list<array{week: int, sold: int, days: int, rate: ?float, weight: int}>}
     */
    public static function demand(array $daily): array
    {
        $daily = array_slice(array_pad($daily, -self::WINDOW_DAYS, 0), -self::WINDOW_DAYS);
        $weeks = [];
        $sum = 0.0;
        $weights = 0;
        for ($w = 1; $w <= 8; $w++) {
            // Week 1 is the most recent seven days.
            $days = array_slice($daily, self::WINDOW_DAYS - 7 * $w, 7);
            $counted = array_values(array_filter($days, fn ($d) => $d !== null));
            $sold = (int) array_sum($counted);
            $rate = $counted === [] ? null : $sold / count($counted);
            $weight = 9 - $w;
            if ($rate !== null) {
                $sum += $rate * $weight;
                $weights += $weight;
            }
            $weeks[] = ['week' => $w, 'sold' => $sold, 'days' => count($counted), 'rate' => $rate === null ? null : round($rate, 3), 'weight' => $weight];
        }

        $counted = array_values(array_filter($daily, fn ($d) => $d !== null));
        $std = 0.0;
        if (count($counted) > 1) {
            $mean = array_sum($counted) / count($counted);
            $std = sqrt(array_sum(array_map(fn ($d) => ($d - $mean) ** 2, $counted)) / (count($counted) - 1));
        }

        return ['avg' => $weights ? $sum / $weights : 0.0, 'std' => $std, 'days' => count($counted), 'weeks' => $weeks];
    }

    /**
     * Days from this order to the next one: to the next order day after today when the
     * supplier has order days, else its review period, else the default.
     *
     * @param  list<int>  $orderDays  ISO weekdays
     */
    public static function reviewDays(array $orderDays, ?int $reviewPeriod, int $default, Carbon $today): int
    {
        if ($orderDays !== []) {
            for ($ahead = 1; $ahead <= 7; $ahead++) {
                if (in_array($today->copy()->addDays($ahead)->isoWeekday(), $orderDays, true)) {
                    return $ahead;
                }
            }
        }

        return max(0, $reviewPeriod ?? $default);
    }

    /** Safety stock in base units: days of demand, or z × std-dev of daily demand × √lead time. */
    public static function safety(string $mode, float $avg, float $std, int $leadDays, float $safetyDays, float $z): float
    {
        return $mode === 'z' ? $z * $std * sqrt(max(0, $leadDays)) : $avg * $safetyDays;
    }

    /** Demand over the cover period plus safety stock, less what's available and already on order. */
    public static function need(float $avg, int $coverDays, float $safety, int $available, int $onOrder): float
    {
        return $avg * $coverDays + $safety - ($available + $onOrder);
    }

    /** The need rounded up to whole supplier packs, at least the MOQ (in packs); 0 when nothing is needed. */
    public static function suggest(float $need, int $packBase, ?int $moqPacks): int
    {
        if ($need <= 0) {
            return 0;
        }
        $pack = max(1, $packBase);
        // Rounded first so float noise (12.0000001 ÷ 6) can't add a whole pack.
        $packs = max((int) ceil(round($need / $pack, 6)), max(0, (int) $moqPacks));

        return $packs * $pack;
    }

    /** Days the available stock lasts at this demand; null when nothing is selling. */
    public static function daysLeft(int $available, float $avg): ?float
    {
        if ($avg <= 0) {
            return null;
        }

        return max(0.0, $available / $avg);
    }

    /** Last 4 weeks against the 4 before: up or down when it moved more than 10%. */
    public static function trend(int $last4, int $prev4): string
    {
        if ($prev4 === 0) {
            return $last4 > 0 ? 'up' : 'flat';
        }
        $change = ($last4 - $prev4) / $prev4;

        return $change > 0.1 ? 'up' : ($change < -0.1 ? 'down' : 'flat');
    }
}
