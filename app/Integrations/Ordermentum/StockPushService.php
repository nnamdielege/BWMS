<?php

namespace App\Integrations\Ordermentum;

use App\Enums\OrderChannel;
use App\Models\ChannelListing;
use App\Models\IntegrationInbox;
use App\Models\Location;
use App\Models\SyncAttempt;
use App\Services\Orders\AvailabilityService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Pushes BWMS available-to-sell to Ordermentum as an absolute figure
 * (spec B3.2): max(0, floor(available_base / pack) - safety buffer). BWMS wins (D-9).
 *
 * Ordermentum decrements tracked stock itself at order placement and never goes
 * negative (confirmed 2026-10-27). An Ordermentum order therefore only becomes a
 * BWMS reservation, and a push must not overwrite a decrement BWMS has not seen
 * yet. So pending order ingestion goes first: while an order event is waiting
 * to be processed, pushes wait; SKUs on an order held for mapping are not
 * pushed until it is mapped.
 *
 * Work comes from listings flagged push_requested_at; one or two items go by
 * PUT, more by bulk PATCH /v1/items. Called from queued jobs only.
 */
class StockPushService
{
    private const CHANNEL = 'ordermentum';

    public function __construct(private OrdermentumClient $client, private AvailabilityService $availability) {}

    public static function site(): Location
    {
        return Location::whereNull('parent_id')->where('type', Location::TYPE_SITE)
            ->where('code', config('bwms.ordermentum.site_code'))->firstOrFail();
    }

    /** Flag listings of these products for the next push run. */
    public static function request(iterable $productIds, bool $force = false): int
    {
        return ChannelListing::requestPush(self::CHANNEL, $productIds, $force);
    }

    public function expectedQty(ChannelListing $listing): int
    {
        $available = $this->availability->available($listing->product, self::site());
        $packs = intdiv(max(0, $available), max(1, $listing->unit->base_qty));

        return max(0, $packs - $listing->safety_buffer);
    }

    /**
     * Seconds to wait because order events are still being ingested, or 0.
     * An event older than ingest_wait_minutes is treated as stuck: logged, not waited for.
     */
    public function ingestionPending(): int
    {
        $oldest = IntegrationInbox::where('channel', self::CHANNEL)->where('status', IntegrationInbox::STATUS_RECEIVED)
            ->where('created_at', '>', now()->subMinutes(config('bwms.ordermentum.ingest_wait_minutes')))->min('created_at');
        if ($oldest) {
            return 15;
        }
        if (IntegrationInbox::where('channel', self::CHANNEL)->where('status', IntegrationInbox::STATUS_RECEIVED)->exists()) {
            Log::warning('Ordermentum order events stuck in the inbox; pushing anyway. Check the queue worker and the sync screen.');
        }

        return 0;
    }

    /**
     * Pushes up to $maxCalls requests' worth of pending listings.
     *
     * @return array{pushed: int, skipped: int, held: int, remaining: int}
     *
     * @throws OrdermentumException on 429 beyond the client's wait, or connection failure (job retries)
     * @throws ChannelRetryableError on 5xx (job retries)
     */
    public function pushPending(int $maxCalls = 10): array
    {
        $chunk = (int) config('bwms.ordermentum.bulk_chunk');
        $listings = ChannelListing::where('channel', self::CHANNEL)->where('sync_enabled', true)->whereNotNull('push_requested_at')
            ->with(['product', 'unit'])->orderBy('push_requested_at')->limit($maxCalls * $chunk)->get();
        $stats = ['pushed' => 0, 'skipped' => 0, 'held' => 0, 'remaining' => 0];

        $heldVariants = $this->heldVariants();
        $toPush = [];
        foreach ($listings as $listing) {
            if (isset($heldVariants[$listing->external_variant_id])) {
                $this->clear($listing, ['last_error' => 'Held: an Ordermentum order with this item needs mapping ('.$heldVariants[$listing->external_variant_id].').']);
                $stats['held']++;

                continue;
            }
            $qty = $this->expectedQty($listing);
            if (! $listing->push_force && $listing->last_pushed_qty === $qty && $listing->last_error === null
                && $listing->last_pushed_at?->gt(now()->subDay())) {
                $this->clear($listing);
                $stats['skipped']++;

                continue;
            }
            $toPush[] = [$listing, $qty];
        }

        if (count($toPush) === 1 || ! config('bwms.ordermentum.bulk_push')) {
            foreach (array_slice($toPush, 0, $maxCalls) as [$listing, $qty]) {
                $this->putOne($listing, $qty);
                $stats['pushed']++;
            }
        } else {
            foreach (array_slice(array_chunk($toPush, $chunk), 0, $maxCalls) as $batch) {
                $this->patchMany($batch);
                $stats['pushed'] += count($batch);
            }
        }

        $stats['remaining'] = ChannelListing::where('channel', self::CHANNEL)->where('sync_enabled', true)->whereNotNull('push_requested_at')->count();

        return $stats;
    }

    private function putOne(ChannelListing $listing, int $qty): void
    {
        $body = array_filter([
            'available' => $qty,
            'tracked' => true,
            'allowOversell' => false,
            'locationId' => $this->locationId($listing),
            'supplierId' => config('services.ordermentum.supplier_id'),
        ], fn ($v) => $v !== null && $v !== '');

        $started = hrtime(true);
        try {
            $response = $this->client->putStock('/v1/items/'.rawurlencode($listing->external_variant_id), $body);
        } catch (OrdermentumException $e) {
            $this->record($listing, 'push', $body, $e->status, $started, $e->getMessage());
            throw $e;
        }
        $this->handleResponse(collect([[$listing, $qty, $body]]), $response->status(), $response->successful(), $response->body(), $started, 'push');
    }

    /** @param  list<array{0: ChannelListing, 1: int}>  $batch */
    private function patchMany(array $batch): void
    {
        // UPDATE is assumed to set the quantity (spec B3.1, unconfirmed); the nightly check would expose otherwise.
        $items = collect($batch)->map(fn ($pair) => [$pair[0], $pair[1], array_filter([
            'id' => $pair[0]->external_variant_id,
            'productId' => $pair[0]->external_product_id,
            'action' => 'UPDATE',
            'quantity' => $pair[1],
            'locationId' => $this->locationId($pair[0]),
        ], fn ($v) => $v !== null && $v !== '')]);

        $started = hrtime(true);
        try {
            $response = $this->client->patchStock('/v1/items', ['actions' => $items->pluck(2)->all()]);
        } catch (OrdermentumException $e) {
            $items->each(fn ($i) => $this->record($i[0], 'push_bulk', $i[2], $e->status, $started, $e->getMessage()));
            throw $e;
        }
        $this->handleResponse($items, $response->status(), $response->successful(), $response->body(), $started, 'push_bulk');
    }

    /** @param  Collection<int, array{0: ChannelListing, 1: int, 2: array}>  $items */
    private function handleResponse(Collection $items, int $status, bool $ok, string $body, int $started, string $operation): void
    {
        $error = $ok ? null : "HTTP {$status}: ".mb_strimwidth($body, 0, 500, '...');
        foreach ($items as [$listing, $qty, $request]) {
            $this->record($listing, $operation, $request, $status, $started, $error);
            $ok
                ? $this->clear($listing, ['last_pushed_qty' => $qty, 'last_pushed_at' => now(), 'last_response_code' => $status, 'last_error' => null])
                : ($status >= 500
                    ? $listing->forceFill(['last_response_code' => $status, 'last_error' => $error])->save() // stays pending; job retries
                    : $this->clear($listing, ['last_response_code' => $status, 'last_error' => $error])); // 4xx: shown on the sync screen
        }
        if (! $ok && $status >= 500) {
            throw new ChannelRetryableError($error);
        }
    }

    private function clear(ChannelListing $listing, array $attributes = []): void
    {
        $listing->clearPushRequest($attributes);
    }

    /** @return array<string, string> variant id => order ref, for orders held in the mapping queue */
    private function heldVariants(): array
    {
        $out = [];
        IntegrationInbox::where('channel', self::CHANNEL)->where('status', IntegrationInbox::STATUS_NEEDS_MAPPING)
            ->get(['external_id', 'meta'])->each(function (IntegrationInbox $i) use (&$out) {
                foreach ((array) ($i->meta['variants'] ?? []) as $v) {
                    $out[$v] = $i->external_id;
                }
            });

        return $out;
    }

    /** Nightly check (B3.2): compares Ordermentum's figure and tracking with ours; never writes BWMS stock. */
    public function check(ChannelListing $listing): ?int
    {
        $listing->loadMissing(['product', 'unit']);
        $location = $this->locationId($listing);
        $started = hrtime(true);
        $response = $this->client->getStock('/v1/items/'.rawurlencode($listing->external_variant_id), $location ? ['locationId' => $location] : []);

        $remote = $response->successful() ? (int) $response->json('available') : null;
        $tracked = $response->successful() ? (bool) $response->json('tracked') : null;
        $expected = $this->expectedQty($listing);
        $error = match (true) {
            $response->status() === 404 => 'Item not found in Ordermentum',
            ! $response->successful() => "HTTP {$response->status()}",
            ! $tracked => 'Stock tracking is off in Ordermentum: it will not decrement on orders',
            $remote !== $expected => "Mismatch: Ordermentum {$remote}, BWMS {$expected}",
            default => null,
        };

        SyncAttempt::create([
            'channel' => OrderChannel::Ordermentum->value, 'operation' => 'check', 'channel_listing_id' => $listing->id,
            'product_id' => $listing->product_id, 'external_id' => $listing->external_variant_id,
            'response_code' => $response->status(), 'duration_ms' => $this->ms($started), 'success' => $error === null, 'error' => $error,
        ]);
        $listing->forceFill([
            'last_remote_qty' => $remote, 'last_checked_at' => now(), 'remote_tracked' => $tracked,
            'remote_status' => match (true) {
                $response->status() === 404 => 'missing', ! $response->successful() => 'error', $tracked => 'tracked', default => 'untracked',
            },
        ])->save();

        return $remote;
    }

    private function locationId(ChannelListing $listing): ?string
    {
        return $listing->external_location_id ?: config('services.ordermentum.location_id');
    }

    private function record(ChannelListing $listing, string $operation, array $body, ?int $status, int $started, ?string $error): void
    {
        SyncAttempt::create([
            'channel' => OrderChannel::Ordermentum->value,
            'operation' => $operation,
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
