<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Inventory;
use App\Services\NotificationService;

class CheckLowStock extends Command
{
    protected $signature = 'inventory:check-low-stock';
    protected $description = 'Check for low stock items and send notifications';

    public function handle()
    {
        $lowStockThreshold = 20;

        $lowStockItems = Inventory::with(['product', 'warehouse'])
            ->where('quantity_on_hand', '<=', $lowStockThreshold)
            ->where('quantity_on_hand', '>', 0)
            ->get();

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