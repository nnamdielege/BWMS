<?php

namespace App\Services\Reports;

/**
 * The arithmetic behind the stock value and drop reports, in one place so it can be
 * tested on its own. Money is dollars ex GST; costs are COGS (weighted average).
 */
final class MarginMath
{
    /** A per-base-unit figure for a whole pack: avg_cost is stored per base unit. */
    public static function perPack(float $perBase, int $packQty): float
    {
        return $perBase * max(1, $packQty);
    }

    /** A price quoted for one pack, restated for another: $12 a 6-pack is $24 a 12-pack. */
    public static function convertPackPrice(float $price, int $fromPackQty, int $toPackQty): float
    {
        return $price / max(1, $fromPackQty) * max(1, $toPackQty);
    }

    /** Gross margin % = (sell − cost) / sell. Null without a sell price above zero or without a cost. */
    public static function gmPercent(?float $sell, ?float $cost): ?float
    {
        if ($sell === null || $cost === null || $sell <= 0) {
            return null;
        }

        return round(($sell - $cost) / $sell * 100, 2);
    }

    /**
     * Packs one drop must carry for its gross profit to cover the delivery: ceil(delivery / (sell − cost)).
     * Null when each pack makes nothing, so no number of packs ever covers it.
     */
    public static function breakEvenUnits(float $deliveryCost, float $sell, float $cost): ?int
    {
        $margin = round($sell - $cost, 6);
        if ($margin <= 0) {
            return null;
        }

        // Rounded first so float noise (30 / 5.0000000001) can't push it up a whole pack.
        return (int) ceil(round($deliveryCost / $margin, 6));
    }

    /**
     * One drop of $units packs of a product: sales, cost of goods, gross profit, and net after delivery.
     *
     * @return array{sales: float, cost: float, gp: float, net: float, gm_percent: ?float, break_even: ?int}
     */
    public static function drop(int $units, float $sell, float $cost, float $deliveryCost): array
    {
        $sales = round($units * $sell, 2);
        $cogs = round($units * $cost, 2);
        $gp = round($sales - $cogs, 2);

        return [
            'sales' => $sales,
            'cost' => $cogs,
            'gp' => $gp,
            'net' => round($gp - $deliveryCost, 2),
            'gm_percent' => self::gmPercent($sell, $cost),
            'break_even' => self::breakEvenUnits($deliveryCost, $sell, $cost),
        ];
    }

    /** Weighted GM % over a set: total GP over total sales, so big lines count for more. */
    public static function weightedGmPercent(float $totalSell, float $totalCost): ?float
    {
        return self::gmPercent($totalSell, $totalCost);
    }
}
