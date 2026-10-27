<?php

namespace App\Integrations\Portal;

use App\Models\ChannelListing;
use App\Models\Location;
use App\Models\SyncAttempt;
use App\Services\Orders\AvailabilityService;

/**
 * BWMS -> portal stock (spec B4.4): PUT {portal}/api/bwms/v1/stock in batches
 * of up to 100, available = max(0, floor(ATS_base / pack)) (the portal's
 * stock_qty is unsigned, B4.3). `as_of` lets the portal ignore out-of-order
 * deliveries. Portal orders reserve synchronously through the API, so unlike
 * Ordermentum there is no ingestion to wait for.
 */
class PortalStockPushService
{
    public const CHANNEL = 'portal';

    public function __construct(private PortalClient $client, private AvailabilityService $availability) {}

    public static function site(): Location
    {
        return Location::whereNull('parent_id')->where('type', Location::TYPE_SITE)->where('code', config('bwms.portal.site_code'))->firstOrFail();
    }

    public function expectedQty(ChannelListing $listing): int
    {
        return intdiv(max(0, $this->availability->available($listing->product, self::site())), max(1, $listing->unit->base_qty));
    }

    /**
     * @return array{pushed: int, skipped: int, remaining: int}
     *
     * @throws PortalUnavailable on connection failure or 5xx (job retries; items stay pending)
     */
    public function pushPending(int $maxCalls = 5): array
    {
        $batch = (int) config('bwms.portal.push_batch');
        $listings = ChannelListing::where('channel', self::CHANNEL)->where('sync_enabled', true)->whereNotNull('push_requested_at')
            ->with(['product', 'unit'])->orderBy('push_requested_at')->limit($maxCalls * $batch)->get();
        $stats = ['pushed' => 0, 'skipped' => 0, 'remaining' => 0];

        $toPush = [];
        foreach ($listings as $listing) {
            $qty = $this->expectedQty($listing);
            if (! $listing->push_force && $listing->last_pushed_qty === $qty && $listing->last_error === null && $listing->last_pushed_at?->gt(now()->subDay())) {
                $listing->clearPushRequest();
                $stats['skipped']++;

                continue;
            }
            $toPush[] = [$listing, $qty];
        }

        foreach (array_chunk($toPush, $batch) as $chunk) {
            $asOf = now()->utc()->format('Y-m-d\TH:i:s.u\Z');
            $items = array_map(fn ($p) => ['portal_product_id' => (int) $p[0]->external_product_id, 'available' => $p[1], 'as_of' => $asOf], $chunk);
            $started = hrtime(true);
            try {
                $response = $this->client->putStock($items);
            } catch (PortalUnavailable $e) {
                $this->record($chunk, null, $started, $e->getMessage());
                throw $e;
            }

            $ok = $response->successful();
            $error = $ok ? null : "HTTP {$response->status()}: ".mb_strimwidth($response->body(), 0, 500, '...');
            $this->record($chunk, $response->status(), $started, $error);
            foreach ($chunk as [$listing, $qty]) {
                if ($ok) {
                    $listing->clearPushRequest(['last_pushed_qty' => $qty, 'last_pushed_at' => now(), 'last_response_code' => $response->status(), 'last_error' => null]);
                } elseif ($response->serverError()) {
                    $listing->forceFill(['last_response_code' => $response->status(), 'last_error' => $error])->save(); // stays pending
                } else {
                    $listing->clearPushRequest(['last_response_code' => $response->status(), 'last_error' => $error]); // 4xx: shown on the sync screen
                }
            }
            if ($response->serverError()) {
                throw new PortalUnavailable($error);
            }
            $stats['pushed'] += $ok ? count($chunk) : 0;
        }

        $stats['remaining'] = ChannelListing::where('channel', self::CHANNEL)->where('sync_enabled', true)->whereNotNull('push_requested_at')->count();

        return $stats;
    }

    /** @param  list<array{0: ChannelListing, 1: int}>  $chunk */
    private function record(array $chunk, ?int $status, int $started, ?string $error): void
    {
        foreach ($chunk as [$listing, $qty]) {
            SyncAttempt::create([
                'channel' => self::CHANNEL, 'operation' => 'push', 'channel_listing_id' => $listing->id, 'product_id' => $listing->product_id,
                'external_id' => $listing->external_product_id, 'request' => ['available' => $qty], 'response_code' => $status,
                'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000), 'success' => $error === null, 'error' => $error,
            ]);
        }
    }
}
