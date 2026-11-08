<?php

namespace App\Listeners;

use App\Events\StockChanged;
use App\Services\Orders\OversoldCheck;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * StockChanged (after commit: receipts, adjustments, stocktakes, cancelled or
 * reduced orders) -> clear the oversold flag on open orders the stock now covers.
 * The stock change has already committed, so a failure here is logged, not thrown.
 */
class RecheckOversoldOrders
{
    public function __construct(private OversoldCheck $check) {}

    public function handle(StockChanged $event): void
    {
        try {
            $this->check->recheck($event->productIds);
        } catch (Throwable $e) {
            Log::error('Oversold re-check failed', ['products' => $event->productIds, 'error' => $e->getMessage()]);
        }
    }
}
