<?php

namespace App\Jobs;

use App\Integrations\Ordermentum\OrdermentumException;
use App\Integrations\Ordermentum\StockPushService;
use App\Models\ChannelListing;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;

/** Nightly check of one listing (figure and tracking); a mismatch triggers a forced push (D-9: BWMS wins). Rate-limited by the client. */
class ReconcileChannelListing implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 30;

    public int $maxExceptions = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(public int $listingId) {}

    public function retryUntil(): Carbon
    {
        return now()->addHours(6);
    }

    public function handle(StockPushService $service): void
    {
        $listing = ChannelListing::with(['product', 'unit'])->find($this->listingId);
        if (! $listing || ! $listing->sync_enabled) {
            return;
        }

        try {
            $remote = $service->check($listing);
        } catch (OrdermentumException $e) {
            if ($e->retryAfter !== null) {
                $this->release($e->retryAfter);

                return;
            }
            throw $e;
        }

        if ($remote !== null && $remote !== $service->expectedQty($listing) && config('bwms.ordermentum.push_enabled')) {
            PushOrdermentumStock::request([$listing->product_id], force: true);
        }
    }
}
