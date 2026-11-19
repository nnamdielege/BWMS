<?php

namespace App\Services\Stock;

use App\Models\Batch;
use App\Models\Location;
use App\Models\Product;
use App\Models\Setting;
use App\Models\StockBalance;
use App\Services\Purchasing\ReorderPlanner;
use App\Support\BusinessDate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Batches in sellable bins whose best-before falls within the next N days (company
 * setting, default 14), or has already passed, with whether they'll sell in time.
 *
 * Picking is FEFO, so a batch only starts selling once the batches picked before it
 * are gone: undated UNKNOWN stock first, then earlier best-before, then earlier
 * received. Expected sold by the best-before = daily demand (ReorderPlanner's
 * 8-week velocity) × days left including the best-before day; what's left of this batch
 * after the batches ahead of it is what won't sell.
 */
class ExpiringSoon
{
    public const STATUS_EXPIRED = 'expired';

    public const STATUS_AT_RISK = 'at_risk';

    public const STATUS_OK = 'ok';

    public function __construct(private ReorderPlanner $planner) {}

    /** @return array{days: int, rows: list<array>, totals: array{batches: int, at_risk: int, expired: int, units_left: int, value_left: float}} */
    public function build(?int $days = null): array
    {
        $days ??= Setting::expiringDays();
        $tz = config('bwms.business_timezone');
        $today = Carbon::parse(BusinessDate::today(), $tz);
        $horizon = $today->copy()->addDays($days)->toDateString();

        // Every sellable-bin batch of the products that have something expiring, so we know what's picked first.
        $productIds = $this->sellable()->whereNotNull('batches.best_before')->where('batches.best_before', '<=', $horizon)
            ->distinct()->pluck('stock_balances.product_id')->all();
        if ($productIds === []) {
            return ['days' => $days, 'rows' => [], 'totals' => ['batches' => 0, 'at_risk' => 0, 'expired' => 0, 'units_left' => 0, 'value_left' => 0.0]];
        }

        $stock = $this->sellable()->whereIn('stock_balances.product_id', $productIds)
            ->groupBy('stock_balances.product_id', 'stock_balances.batch_id')
            ->selectRaw('stock_balances.product_id, stock_balances.batch_id, SUM(stock_balances.on_hand) AS qty')
            ->get();
        $batches = Batch::whereIn('id', $stock->pluck('batch_id'))->get()->keyBy('id');
        $products = Product::with('supplier:id,name')->whereIn('id', $productIds)->get()->keyBy('id');
        $velocity = $this->planner->dailyVelocity($productIds);

        $rows = [];
        foreach ($stock->groupBy('product_id') as $productId => $lines) {
            $product = $products[$productId];
            $rate = (float) ($velocity[$productId] ?? 0);
            $ahead = 0; // sellable units picked before this batch
            foreach ($this->fefo($lines, $batches) as [$batch, $qty]) {
                $expired = $batch->isExpired();
                $bb = $batch->best_before?->toDateString();
                if ($bb !== null && $bb <= $horizon) {
                    $daysLeft = (int) $today->diffInDays(Carbon::parse($bb, $tz), false);
                    $p = self::project($qty, $ahead, $rate, $expired ? 0 : $daysLeft + 1);
                    $rows[] = [
                        'product_id' => $product->id,
                        'sku' => $product->sku,
                        'name' => $product->name,
                        'base_unit' => $product->base_unit,
                        'supplier' => $product->supplier?->name,
                        'batch_id' => $batch->id,
                        'batch' => $batch->lot_code,
                        'best_before' => $bb,
                        'days_left' => $daysLeft,
                        'on_hand' => $qty,
                        'ahead' => $ahead,
                        'daily_rate' => round($rate, 2),
                        'expected_sold' => $p['sold'],
                        'left_over' => $p['left'],
                        'status' => $expired ? self::STATUS_EXPIRED : ($p['left'] > 0 ? self::STATUS_AT_RISK : self::STATUS_OK),
                        'unit_cost' => (float) $product->avg_cost > 0 ? (float) $product->avg_cost : null,
                        'value_left' => round($p['left'] * (float) $product->avg_cost, 2),
                    ];
                }
                // Expired stock isn't sellable, so it holds nothing up.
                if (! $expired) {
                    $ahead += $qty;
                }
            }
        }
        usort($rows, fn ($a, $b) => [$a['best_before'], $a['sku']] <=> [$b['best_before'], $b['sku']]);

        return ['days' => $days, 'rows' => $rows, 'totals' => [
            'batches' => count($rows),
            'at_risk' => count(array_filter($rows, fn ($r) => $r['status'] === self::STATUS_AT_RISK)),
            'expired' => count(array_filter($rows, fn ($r) => $r['status'] === self::STATUS_EXPIRED)),
            'units_left' => array_sum(array_column($rows, 'left_over')),
            'value_left' => round(array_sum(array_column($rows, 'value_left')), 2),
        ]];
    }

    /**
     * How much of a batch sells before its best-before.
     *
     * @param  int  $qty  units of this batch in sellable bins
     * @param  int  $ahead  sellable units picked before it (FEFO)
     * @param  float  $rate  units sold a day
     * @param  int  $sellingDays  days it can still be sold, today and the best-before day included (0 once expired)
     * @return array{sold: int, left: int}
     */
    public static function project(int $qty, int $ahead, float $rate, int $sellingDays): array
    {
        $demand = $rate * max(0, $sellingDays);
        // Whole units: a fraction of a unit left over isn't a unit that won't sell.
        $sold = (int) min($qty, max(0, floor($demand - $ahead + 1e-9)));

        return ['sold' => $sold, 'left' => $qty - $sold];
    }

    /** Batches in sellable bins (active, non-quarantine), on hand only. */
    private function sellable()
    {
        return StockBalance::query()
            ->join('batches', 'batches.id', '=', 'stock_balances.batch_id')
            ->whereIn('stock_balances.location_id', Location::sellable()->select('id'))
            ->where('stock_balances.on_hand', '>', 0);
    }

    /**
     * One product's batches in the order picking takes them (FefoAllocator's order).
     *
     * @return list<array{0: Batch, 1: int}>
     */
    private function fefo(Collection $lines, Collection $batches): array
    {
        $out = $lines->map(fn ($l) => [$batches[$l->batch_id], (int) $l->qty])->all();
        usort($out, fn ($a, $b) => self::pickOrder($a[0]) <=> self::pickOrder($b[0]));

        return $out;
    }

    private static function pickOrder(Batch $b): array
    {
        $unknown = $b->lot_code === Batch::UNKNOWN_LOT && $b->best_before === null;

        return [$unknown ? 0 : 1, $b->best_before === null ? 1 : 0, $b->best_before?->toDateString() ?? '', $b->received_on?->toDateString() ?? '', $b->id];
    }
}
