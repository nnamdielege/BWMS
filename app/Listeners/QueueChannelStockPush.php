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
    private static bool $muted = false;

    /**
     * Run a clean-up that must send nothing to any channel. Other StockChanged
     * listeners (e.g. the oversold re-check) still run.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public static function muted(callable $work): mixed
    {
        $before = self::$muted;
        self::$muted = true;
        try {
            return $work();
        } finally {
            self::$muted = $before;
        }
    }

    public function handle(StockChanged $event): void
    {
        if (self::$muted) {
            return;
        }
        PushOrdermentumStock::request($event->productIds);
        PushPortalStock::request($event->productIds);
    }
}
