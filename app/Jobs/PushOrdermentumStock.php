<?php

namespace App\Jobs;

use App\Integrations\Ordermentum\OrdermentumException;
use App\Integrations\Ordermentum\StockPushService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;

/**
 * Drains pending Ordermentum stock pushes (NFR-3). Stock changes only flag
 * listings and dispatch this job with a delay; it is unique until it starts,
 * so a burst of changes becomes one run that sends the latest figures, in
 * bulk where possible. Runs never overlap; leftovers trigger another run.
 */
class PushOrdermentumStock implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 80; // queue retry_after (90s) must exceed this; the client may sleep out short limits

    public int $maxExceptions = 8;

    public int $uniqueFor = 600;

    /** @var list<int> */
    public array $backoff = [10, 30, 60, 120, 300];

    public function uniqueId(): string
    {
        return 'ordermentum-stock';
    }

    /** Waiting for ingestion or rate limits are releases, not failures; give up only after this window. */
    public function retryUntil(): Carbon
    {
        return now()->addHours(6);
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('om-stock-push'))->releaseAfter(10)->expireAfter(300)];
    }

    /** Flag the products and queue a run after the debounce delay. */
    public static function request(iterable $productIds, bool $force = false): void
    {
        if (! config('bwms.ordermentum.push_enabled')) {
            return;
        }
        if (StockPushService::request($productIds, $force) > 0) {
            static::dispatch()->delay(now()->addSeconds(config('bwms.ordermentum.debounce_seconds')));
        }
    }

    public function handle(StockPushService $pusher): void
    {
        if (! config('bwms.ordermentum.push_enabled')) {
            return;
        }

        // Order events first: a push must not overwrite a decrement BWMS has not seen yet.
        if ($wait = $pusher->ingestionPending()) {
            $this->release($wait);

            return;
        }

        try {
            $stats = $pusher->pushPending();
        } catch (OrdermentumException $e) {
            if ($e->retryAfter !== null) {
                $this->release($e->retryAfter);

                return;
            }
            throw $e;
        }

        if ($stats['remaining'] > 0) {
            static::dispatch()->delay(now()->addSeconds(5));
        }
    }
}
