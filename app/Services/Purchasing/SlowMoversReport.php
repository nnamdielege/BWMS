<?php

namespace App\Services\Purchasing;

use App\Models\Product;
use App\Models\StockBalance;

/**
 * Purchasing → Slow movers: stock on hand that is barely selling (under 0.1 base units a day on
 * the reorder velocity), by value at cost, largest first.
 */
class SlowMoversReport
{
    public const MAX_VELOCITY = 0.1;

    public function __construct(private ReorderPlanner $planner) {}

    /**
     * @return array{rows: list<array{product_id: int, sku: string, name: string, base_unit: string, supplier: ?string, on_hand: int,
     *               velocity: float, avg_cost: float, value: float, days_cover: ?float}>, total_value: float}
     */
    public function build(): array
    {
        $onHand = StockBalance::groupBy('product_id')->havingRaw('SUM(on_hand) > 0')->selectRaw('product_id, SUM(on_hand) AS qty')->pluck('qty', 'product_id');
        if ($onHand->isEmpty()) {
            return ['rows' => [], 'total_value' => 0.0];
        }
        $products = Product::with('supplier:id,name')->whereIn('id', $onHand->keys())->where('is_stock_tracked', true)->get();
        $velocity = $this->planner->dailyVelocity($products->pluck('id')->all());

        $rows = $products->filter(fn (Product $p) => ($velocity[$p->id] ?? 0.0) < self::MAX_VELOCITY)
            ->map(function (Product $p) use ($onHand, $velocity) {
                $qty = (int) $onHand[$p->id];
                $v = $velocity[$p->id] ?? 0.0;

                return [
                    'product_id' => $p->id, 'sku' => $p->sku, 'name' => $p->name, 'base_unit' => $p->base_unit,
                    'supplier' => $p->supplier?->name, 'is_active' => $p->is_active,
                    'on_hand' => $qty, 'velocity' => round($v, 3), 'avg_cost' => (float) $p->avg_cost,
                    'value' => round($qty * (float) $p->avg_cost, 2),
                    'days_cover' => $v > 0 ? round($qty / $v, 0) : null, // null: nothing sold in 8 weeks
                ];
            })
            ->sortByDesc('value')->values();

        return ['rows' => $rows->all(), 'total_value' => round($rows->sum('value'), 2)];
    }
}
