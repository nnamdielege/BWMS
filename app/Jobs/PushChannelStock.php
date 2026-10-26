<?php

namespace App\Jobs;

use App\Enums\OrderChannel;
use App\Integrations\Ordermentum\OrdermentumException;
use App\Integrations\Ordermentum\StockPushService;
use App\Models\ChannelListing;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;

/**
 * Pushes one product's available stock to its enabled Ordermentum listings
 * (NFR-3). Unique per product until it starts, and dispatched with a delay, so
 * a burst of movements becomes one push carrying the latest figure; a change
 * during a running push queues the next one.
 */
class PushChannelStock implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 30; // queue retry_after (90s) must exceed this

    public int $maxExceptions = 8;

    public int $uniqueFor = 600;

    /** @var list<int> */
    public array $backoff = [10, 30, 60, 120, 300];

    public function __construct(public int $productId, public bool $force = false) {}

    public function uniqueId(): string
    {
        return OrderChannel::Ordermentum->value.':'.$this->productId;
    }

    /** Rate-limit releases are not failures; give up only after this window or maxExceptions. */
    public function retryUntil(): Carbon
    {
        return now()->addHours(6);
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('om-push:'.$this->productId))->releaseAfter(5)->expireAfter(120),
            new RateLimited('ordermentum-stock'),
        ];
    }

    public function handle(StockPushService $pusher): void
    {
        if (! config('bwms.ordermentum.push_enabled')) {
            return;
        }

        $listings = ChannelListing::where('channel', OrderChannel::Ordermentum->value)
            ->where('product_id', $this->productId)->where('sync_enabled', true)->get();

        foreach ($listings as $listing) {
            try {
                $pusher->push($listing, $this->force);
            } catch (OrdermentumException $e) {
                if ($e->retryAfter !== null) {
                    $this->release($e->retryAfter); // 429 longer than the client waits

                    return;
                }
                throw $e;
            }
        }
    }
}
