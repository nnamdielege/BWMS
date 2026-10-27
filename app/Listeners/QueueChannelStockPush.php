<?php

namespace App\Listeners;

use App\Events\StockChanged;
use App\Jobs\PushOrdermentumStock;
use App\Jobs\PushPortalStock;

/**
 * StockChanged (after commit) -> flag the products' enabled Ordermentum and
 * portal listings and queue one debounced push run per channel. Never calls an API.
 */
class QueueChannelStockPush
{
    public function handle(StockChanged $event): void
    {
        PushOrdermentumStock::request($event->productIds);
        PushPortalStock::request($event->productIds);
    }
}
