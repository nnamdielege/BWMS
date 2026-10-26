<?php

namespace App\Listeners;

use App\Enums\OrderChannel;
use App\Events\StockChanged;
use App\Jobs\PushChannelStock;
use App\Models\ChannelListing;

/**
 * StockChanged (after commit) -> one debounced push job per product that has
 * an enabled Ordermentum listing. Only queues work; never calls the API.
 */
class QueueChannelStockPush
{
    public function handle(StockChanged $event): void
    {
        if (! config('bwms.ordermentum.push_enabled') || $event->productIds === []) {
            return;
        }

        $productIds = ChannelListing::where('channel', OrderChannel::Ordermentum->value)->where('sync_enabled', true)
            ->whereIn('product_id', $event->productIds)->distinct()->pluck('product_id');

        foreach ($productIds as $productId) {
            PushChannelStock::dispatch($productId)->delay(now()->addSeconds(config('bwms.ordermentum.debounce_seconds')));
        }
    }
}
