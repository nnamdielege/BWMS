<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Inventory;
use App\Models\Setting;
use App\Services\NotificationService;

class CheckLowStock extends Command
{
    protected $signature = 'inventory:check-low-stock';
    protected $description = 'Check for low stock items and send notifications';

    public function handle()
    {
        // Try to find the threshold setting by key
        $setting = Setting::where('key', 'low_stock_threshold')->first();

        // Use setting value if available, otherwise default to 20
        $lowStockThreshold = $setting?->value ?? 20;

        $this->info("Low Stock Threshold: {$lowStockThreshold} ");

        $lowStockItems = Inventory::with(['product', 'warehouse'])
            ->where('quantity_on_hand', '<=', $lowStockThreshold)
            ->where('quantity_on_hand', '>', 0)
            ->get();

        if ($lowStockItems->isEmpty()) {
            $this->info('✅ No low-stock items found.');
            return;
        }

        foreach ($lowStockItems as $inventory) {
            NotificationService::lowStock(
                $inventory->product,
                $inventory->warehouse,
                $inventory->quantity_on_hand
            );
        }

        $this->info("Checked {$lowStockItems->count()} low stock items");
    }
}