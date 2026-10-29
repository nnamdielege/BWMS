<?php

namespace App\Integrations\Ordermentum;

use Illuminate\Support\Facades\Storage;

/**
 * Local testing only: Ordermentum orders kept as JSON files, used by the
 * ingestion instead of fetching the order. om:simulate-webhook writes them, so
 * an edit or a cancellation can be tested without changing anything in
 * Ordermentum. Off unless ORDERMENTUM_LOCAL_FIXTURES=true and APP_ENV is local
 * or testing.
 */
class LocalOrderFixtures
{
    private const DIR = 'ordermentum-fixtures';

    public function enabled(): bool
    {
        return (bool) config('bwms.ordermentum.local_order_fixtures') && app()->environment(['local', 'testing']);
    }

    /** @return array<string, mixed>|null */
    public function get(string $orderId): ?array
    {
        if (! $this->enabled() || ! Storage::disk('local')->exists($this->path($orderId))) {
            return null;
        }

        return json_decode(Storage::disk('local')->get($this->path($orderId)), true);
    }

    /** @param  array<string, mixed>  $order */
    public function put(string $orderId, array $order): void
    {
        Storage::disk('local')->put($this->path($orderId), json_encode($order, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function clear(): void
    {
        Storage::disk('local')->deleteDirectory(self::DIR);
    }

    private function path(string $orderId): string
    {
        return self::DIR.'/'.preg_replace('/[^A-Za-z0-9_-]/', '_', $orderId).'.json';
    }
}
