<?php

namespace App\Jobs;

use App\Integrations\Portal\PortalStockPushService;
use App\Models\ChannelListing;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;

/** Drains pending portal stock pushes: unique until processing, debounced, never overlapping (NFR-3). */
class PushPortalStock implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 60; // queue retry_after (90s) must exceed this

    public int $maxExceptions = 8;

    public int $uniqueFor = 600;

    /** @var list<int> */
    public array $backoff = [10, 30, 60, 300, 900];

    public function uniqueId(): string
    {
        return 'portal-stock';
    }

    public function retryUntil(): Carbon
    {
        return now()->addHours(12);
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('portal-stock-push'))->releaseAfter(10)->expireAfter(180)];
    }

    /** Flag the products and queue a run after the debounce delay. */
    public static function request(iterable $productIds, bool $force = false): void
    {
        if (! config('bwms.portal.push_enabled')) {
            return;
        }
        if (ChannelListing::requestPush(PortalStockPushService::CHANNEL, $productIds, $force) > 0) {
            static::dispatch()->delay(now()->addSeconds(config('bwms.portal.debounce_seconds')));
        }
    }

    public function handle(PortalStockPushService $pusher): void
    {
        if (! config('bwms.portal.push_enabled')) {
            return;
        }
        if ($pusher->pushPending()['remaining'] > 0) {
            static::dispatch()->delay(now()->addSeconds(5));
        }
    }
}
