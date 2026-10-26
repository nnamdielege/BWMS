<?php

namespace App\Services\Stock;

use App\Models\Location;
use App\Models\Product;
use App\Models\StockBalance;
use App\Support\BusinessDate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Read side of the ledger. All figures are in base units.
 */
class StockQuery
{
    /** Total on hand in all bins (includes quarantine and expired). */
    public function onHand(Product $product): int
    {
        return (int) StockBalance::where('product_id', $product->id)->sum('on_hand');
    }

    /**
     * On hand that could be sold: sellable, active, non-quarantine bins and no
     * expired batches (D-13). Reservations are subtracted by the availability
     * service added in step 5 (available = on_hand - reserved).
     */
    public function sellableOnHand(Product $product): int
    {
        return (int) $this->sellableBalances()->where('stock_balances.product_id', $product->id)->sum('stock_balances.on_hand');
    }

    /** @return array<int, int> product_id => sellable on hand */
    public function sellableOnHandMany(array $productIds): array
    {
        return $this->sellableBalances()
            ->whereIn('stock_balances.product_id', $productIds)
            ->groupBy('stock_balances.product_id')
            ->selectRaw('stock_balances.product_id, SUM(stock_balances.on_hand) as qty')
            ->pluck('qty', 'stock_balances.product_id')
            ->map(fn ($q) => (int) $q)
            ->all();
    }

    /** @return Collection<int, StockBalance> non-zero balances with location and batch */
    public function balancesFor(Product $product): Collection
    {
        return StockBalance::where('product_id', $product->id)
            ->where('on_hand', '!=', 0)
            ->with(['location.parent.parent', 'batch'])
            ->get()
            ->sortBy([fn ($b) => $b->batch?->best_before?->timestamp ?? PHP_INT_MAX, fn ($b) => $b->location->code])
            ->values();
    }

    /** IDs of bins under a site/zone (or the bin itself). */
    public function binIdsUnder(Location $location): array
    {
        if ($location->isBin()) {
            return [$location->id];
        }

        $ids = [];
        $frontier = [$location->id];
        while ($frontier !== []) {
            $children = Location::whereIn('parent_id', $frontier)->get(['id', 'type']);
            $ids = [...$ids, ...$children->where('type', Location::TYPE_BIN)->pluck('id')->all()];
            $frontier = $children->where('type', '!=', Location::TYPE_BIN)->pluck('id')->all();
        }

        return $ids;
    }

    private function sellableBalances(): Builder
    {
        return StockBalance::query()
            ->join('locations', 'locations.id', '=', 'stock_balances.location_id')
            ->leftJoin('batches', 'batches.id', '=', 'stock_balances.batch_id')
            ->where('locations.type', Location::TYPE_BIN)
            ->where('locations.is_sellable', true)
            ->where('locations.is_quarantine', false)
            ->where('locations.is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('batches.best_before')->orWhere('batches.best_before', '>=', BusinessDate::today()));
    }
}
