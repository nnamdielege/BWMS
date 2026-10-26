<?php

namespace App\Integrations\Ordermentum;

use App\Enums\OrderChannel;
use App\Models\ChannelListing;
use App\Models\Location;
use App\Models\SyncAttempt;
use App\Services\Orders\AvailabilityService;

/**
 * Pushes BWMS available-to-sell to Ordermentum as an absolute figure (spec B3.2,
 * D-3): available = max(0, floor(ATS_base / pack) - safety buffer), tracked,
 * allowOversell false. BWMS always wins (D-9). Called from queued jobs only.
 */
class StockPushService
{
    public function __construct(private OrdermentumClient $client, private AvailabilityService $availability) {}

    public static function site(): Location
    {
        return Location::whereNull('parent_id')->where('type', Location::TYPE_SITE)
            ->where('code', config('bwms.ordermentum.site_code'))->firstOrFail();
    }

    public function expectedQty(ChannelListing $listing): int
    {
        $available = $this->availability->available($listing->product, self::site());
        $packs = intdiv(max(0, $available), max(1, $listing->unit->base_qty));

        return max(0, $packs - $listing->safety_buffer);
    }

    /**
     * @return bool false when skipped (unchanged and pushed within 24h)
     *
     * @throws OrdermentumException on 429 beyond the client's wait, or connection failure (job retries)
     * @throws ChannelRetryableError on 5xx (job retries)
     */
    public function push(ChannelListing $listing, bool $force = false): bool
    {
        $listing->loadMissing(['product', 'unit']);
        $qty = $this->expectedQty($listing);

        if (! $force && $listing->last_pushed_qty === $qty && $listing->last_error === null
            && $listing->last_pushed_at?->gt(now()->subDay())) {
            return false;
        }

        $body = array_filter([
            'available' => $qty,
            'tracked' => true,
            'allowOversell' => false,
            'locationId' => $listing->external_location_id ?: config('services.ordermentum.location_id'),
            'supplierId' => config('services.ordermentum.supplier_id'),
        ], fn ($v) => $v !== null && $v !== '');

        $started = hrtime(true);
        try {
            $response = $this->client->putStock('/v1/items/'.rawurlencode($listing->external_variant_id), $body);
        } catch (OrdermentumException $e) {
            $this->record($listing, $body, $e->status, $started, $e->getMessage());
            throw $e;
        }

        if ($response->successful()) {
            $this->record($listing, $body, $response->status(), $started, null);
            $listing->forceFill([
                'last_pushed_qty' => $qty, 'last_pushed_at' => now(), 'last_response_code' => $response->status(), 'last_error' => null,
            ])->save();

            return true;
        }

        $error = "HTTP {$response->status()}: ".mb_strimwidth($response->body(), 0, 500, '...');
        $this->record($listing, $body, $response->status(), $started, $error);
        $listing->forceFill(['last_response_code' => $response->status(), 'last_error' => $error])->save();

        if ($response->serverError()) {
            throw new ChannelRetryableError($error);
        }

        return true; // 4xx: permanent until someone fixes the listing; shown on the sync screen
    }

    /** Nightly check (B3.2): compares Ordermentum's figure with ours; never writes BWMS stock. */
    public function check(ChannelListing $listing): ?int
    {
        $listing->loadMissing(['product', 'unit']);
        $location = $listing->external_location_id ?: config('services.ordermentum.location_id');
        $started = hrtime(true);
        $response = $this->client->getStock('/v1/items/'.rawurlencode($listing->external_variant_id), $location ? ['locationId' => $location] : []);

        $remote = $response->successful() ? (int) $response->json('available') : null;
        $expected = $this->expectedQty($listing);
        $error = match (true) {
            ! $response->successful() => "HTTP {$response->status()}",
            $remote !== $expected => "Mismatch: Ordermentum {$remote}, BWMS {$expected}",
            default => null,
        };

        SyncAttempt::create([
            'channel' => OrderChannel::Ordermentum->value, 'operation' => 'check', 'channel_listing_id' => $listing->id,
            'product_id' => $listing->product_id, 'external_id' => $listing->external_variant_id,
            'response_code' => $response->status(), 'duration_ms' => $this->ms($started), 'success' => $error === null, 'error' => $error,
        ]);
        $listing->forceFill(['last_remote_qty' => $remote, 'last_checked_at' => now()])->save();

        return $remote;
    }

    private function record(ChannelListing $listing, array $body, ?int $status, int $started, ?string $error): void
    {
        SyncAttempt::create([
            'channel' => OrderChannel::Ordermentum->value,
            'operation' => 'push',
            'channel_listing_id' => $listing->id,
            'product_id' => $listing->product_id,
            'external_id' => $listing->external_variant_id,
            'request' => $body,
            'response_code' => $status,
            'duration_ms' => $this->ms($started),
            'success' => $error === null,
            'error' => $error,
        ]);
    }

    private function ms(int $started): int
    {
        return (int) ((hrtime(true) - $started) / 1_000_000);
    }
}
