<?php

namespace App\Services\Stock;

use App\Models\Location;
use App\Models\Product;
use App\Models\StockBalance;
use App\Support\BusinessDate;

/**
 * First-expired-first-out allocation of sellable stock. Used for pick
 * suggestions (step 5). Never allocates from quarantine, non-sellable or
 * inactive bins, or expired batches. Untracked stock (no batch) comes last.
 */
class FefoAllocator
{
    public function __construct(private StockQuery $stock) {}

    /**
     * @return array{lines: list<array{balance: StockBalance, qty: int}>, shortfall: int}
     */
    public function allocate(Product $product, int $qty, ?Location $within = null): array
    {
        $query = StockBalance::query()
            ->select('stock_balances.*')
            ->join('locations', 'locations.id', '=', 'stock_balances.location_id')
            ->leftJoin('batches', 'batches.id', '=', 'stock_balances.batch_id')
            ->where('stock_balances.product_id', $product->id)
            ->where('stock_balances.on_hand', '>', 0)
            ->where('locations.type', Location::TYPE_BIN)
            ->where('locations.is_sellable', true)
            ->where('locations.is_quarantine', false)
            ->where('locations.is_active', true)
            ->where(fn ($q) => $q->whereNull('batches.best_before')->orWhere('batches.best_before', '>=', BusinessDate::today()))
            // Earliest best-before first; batches with no date and untracked stock last.
            ->orderByRaw('batches.best_before IS NULL, batches.best_before ASC')
            ->orderBy('batches.received_on')
            ->orderBy('batches.id')
            ->orderBy('locations.sort_order')
            ->orderBy('locations.code')
            ->with(['location', 'batch']);

        if ($within) {
            $query->whereIn('stock_balances.location_id', $this->stock->binIdsUnder($within));
        }

        $lines = [];
        $remaining = $qty;
        foreach ($query->get() as $balance) {
            if ($remaining <= 0) {
                break;
            }
            $take = min($remaining, $balance->on_hand);
            $lines[] = ['balance' => $balance, 'qty' => $take];
            $remaining -= $take;
        }

        return ['lines' => $lines, 'shortfall' => max(0, $remaining)];
    }
}
