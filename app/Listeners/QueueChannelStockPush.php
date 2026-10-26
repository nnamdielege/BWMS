<?php

namespace App\Listeners;

use App\Events\StockChanged;
use App\Jobs\PushOrdermentumStock;

/**
 * StockChanged (after commit) -> flag the products' enabled Ordermentum
 * listings and queue one debounced push run. Never calls the API.
 */
class QueueChannelStockPush
{
    public function handle(StockChanged $event): void
    {
        PushOrdermentumStock::request($event->productIds);
    }
}
