<?php

namespace App\Services\Orders;

use App\Models\Location;
use App\Models\Product;
use App\Models\Reservation;
use App\Services\Stock\StockQuery;

/**
 * Available-to-sell (spec B2.3, D-2): sellable on hand - reserved, per site or
 * across all sites. May be negative after an Ordermentum oversell (D-3).
 * Channel safety buffers (D-4, default 0) are applied by the channel push.
 */
class AvailabilityService
{
    public function __construct(private StockQuery $stock) {}

    /**
     * @param  bool  $current  locking reads (see StockQuery::sellableOnHand). Without
     *                         this, MariaDB's REPEATABLE READ snapshot hides reservations
     *                         committed by concurrent orders and the check oversells
     *                         (caught by StockLedgerConcurrencyTest).
     */
    public function available(Product $product, ?Location $site = null, bool $current = false): int
    {
        return $this->stock->sellableOnHand($product, $site, $current) - $this->reserved($product, $site, $current);
    }

    public function reserved(Product $product, ?Location $site = null, bool $current = false): int
    {
        return (int) Reservation::where('product_id', $product->id)
            ->when($site, fn ($q) => $q->where('site_id', $site->id))
            ->when($current, fn ($q) => $q->sharedLock())
            ->sum('qty_base');
    }

    /** @return array<int, int> product_id => available across all sites */
    public function availableMany(array $productIds): array
    {
        $sellable = $this->stock->sellableOnHandMany($productIds);
        $reserved = Reservation::whereIn('product_id', $productIds)->groupBy('product_id')
            ->selectRaw('product_id, SUM(qty_base) as qty')->pluck('qty', 'product_id');

        $out = [];
        foreach ($productIds as $id) {
            $out[$id] = ($sellable[$id] ?? 0) - (int) ($reserved[$id] ?? 0);
        }

        return $out;
    }
}
