<?php

namespace App\Services\Catalogue;

use App\Models\Product;
use App\Services\Reports\MarginMath;
use App\Services\Reports\PackPricing;

/**
 * Products whose sell price is below what one sale pack costs: the price is for the sale pack
 * (as Ordermentum's base price), so one under cost was most likely entered per unit.
 *
 * Checks active products with their own sell price and a cost. Cost per pack = average cost ×
 * the sale pack's size, the same pack the reports and the product form use (PackPricing).
 */
class SellPriceCheck
{
    /**
     * @return list<array{id: int, sku: string, name: string, pack: string, pack_qty: int, base_unit: string,
     *                    sell_price: float, cost_per_pack: float, sell_per_unit: float, price_if_per_unit: float}>
     */
    public function belowCost(): array
    {
        return Product::with('units')->where('is_active', true)->where('sell_price', '>', 0)->where('avg_cost', '>', 0)
            ->orderBy('sku')->get()
            ->map(function (Product $p) {
                $pack = PackPricing::reportPack($p);
                $qty = $pack?->base_qty ?? 1;
                $sell = (float) $p->sell_price;
                $cost = MarginMath::perPack((float) $p->avg_cost, $qty);
                if (round($sell, 2) >= round($cost, 2)) {
                    return null;
                }

                return [
                    'id' => $p->id, 'sku' => $p->sku, 'name' => $p->name,
                    'pack' => $pack?->name ?? $p->base_unit, 'pack_qty' => $qty, 'base_unit' => $p->base_unit,
                    'sell_price' => round($sell, 2),
                    'cost_per_pack' => round($cost, 2),
                    'sell_per_unit' => round($sell / $qty, 4),
                    // What the pack price would be if the figure entered was per unit: a hint, not a fix.
                    'price_if_per_unit' => round($sell * $qty, 2),
                ];
            })
            ->filter()->values()->all();
    }
}
