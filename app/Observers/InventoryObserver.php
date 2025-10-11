<?php

namespace App\Observers;

use App\Models\Inventory;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Cache;

class InventoryObserver
{
    public function updated(Inventory $inventory)
    {
        // Get low stock threshold from settings or use default
        $lowStockThreshold = 20;

        // Cache key to prevent duplicate notifications
        $cacheKey = "low_stock_notified_{$inventory->id}";

        // Check for out of stock
        if ($inventory->quantity_on_hand <= 0) {
            if (!Cache::has($cacheKey)) {
                NotificationService::outOfStock(
                    $inventory->product,
                    $inventory->warehouse
                );

                // Cache for 24 hours to prevent spam
                Cache::put($cacheKey, true, now()->addDay());
            }
        }
        // Check for low stock
        elseif ($inventory->quantity_on_hand <= $lowStockThreshold) {
            if (!Cache::has($cacheKey)) {
                NotificationService::lowStock(
                    $inventory->product,
                    $inventory->warehouse,
                    $inventory->quantity_on_hand
                );

                // Cache for 24 hours
                Cache::put($cacheKey, true, now()->addDay());
            }
        }
        // If stock is replenished, clear the cache
        elseif ($inventory->quantity_on_hand > $lowStockThreshold) {
            Cache::forget($cacheKey);
        }
    }
}