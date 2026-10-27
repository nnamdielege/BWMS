<?php

namespace App\Integrations\Ordermentum;

use App\Enums\OrderChannel;
use App\Jobs\PushOrdermentumStock;
use App\Models\ChannelListing;
use App\Models\ChannelUnmappedVariant;
use App\Models\Customer;
use App\Models\IntegrationInbox;
use App\Models\Order;
use App\Models\SyncAttempt;
use App\Services\Orders\ChannelOrderData;
use App\Services\Orders\OrderService;
use App\Services\Stock\Exceptions\InvalidMovement;

/**
 * Ordermentum order -> BWMS order (spec B3.2 inbound). The webhook is only a
 * hint: the order is fetched from the API, which is authoritative and defeats
 * spoofed webhooks. The upsert is full-state and versioned by updatedAt, so
 * replays and out-of-order deliveries are harmless.
 *
 * Ordermentum decrements its own tracked stock at placement and blocks orders
 * beyond it (confirmed 2026-10-27), so an order here is only a BWMS reservation
 * and is never deducted in Ordermentum again. Orders are still always accepted
 * (D-3) as a safety net for sync lag; a shortfall flags the order oversold.
 */
class OrderIngestor
{
    public function __construct(
        private OrdermentumClient $client,
        private OrdermentumOrderMapper $mapper,
        private OrderService $orders,
    ) {}

    /**
     * @return string the inbox status it ended in
     *
     * @throws OrdermentumException on 429 / connection failure (the job retries)
     * @throws ChannelRetryableError on 5xx
     */
    public function ingest(string $orderId, ?IntegrationInbox $inbox = null): string
    {
        $inbox?->increment('attempts');
        $order = $this->fetch($orderId);
        if ($order === null) {
            return $this->finish($inbox, IntegrationInbox::STATUS_FAILED, "Order {$orderId} not found in Ordermentum (404).");
        }

        try {
            $mapped = $this->mapper->map($order);
        } catch (UnmappableOrder $e) {
            return $this->finish($inbox, IntegrationInbox::STATUS_FAILED, $e->getMessage());
        }

        $listings = ChannelListing::where('channel', OrderChannel::Ordermentum->value)
            ->whereIn('external_variant_id', array_column($mapped['lines'], 'variant_id'))->get()->keyBy('external_variant_id');
        $unmapped = array_values(array_filter($mapped['lines'], fn ($l) => ! isset($listings[$l['variant_id']])));
        if ($unmapped !== [] && ! $mapped['cancelled']) {
            foreach ($unmapped as $line) {
                $this->rememberUnmapped($line);
            }

            // meta.variants: the order's mapped SKUs are not pushed while it waits (a push would hide its decrement).
            $inbox?->forceFill(['meta' => ['variants' => array_values(array_unique(array_column($mapped['lines'], 'variant_id')))]])->save();

            return $this->finish($inbox, IntegrationInbox::STATUS_NEEDS_MAPPING,
                'Unmapped variant(s): '.implode(', ', array_unique(array_column($unmapped, 'variant_id'))).'. Map them on the sync screen, then replay.');
        }

        $data = new ChannelOrderData(
            channel: OrderChannel::Ordermentum,
            externalRef: $mapped['external_ref'],
            version: $mapped['version'],
            status: $mapped['cancelled'] ? 'cancelled' : 'confirmed',
            siteId: StockPushService::site()->id,
            lines: array_map(fn ($l) => [
                'line_ref' => $l['line_ref'],
                'product_id' => $listings[$l['variant_id']]->product_id,
                'product_unit_id' => $listings[$l['variant_id']]->product_unit_id,
                'qty' => $l['quantity'],
                'unit_price' => $l['unit_price'],
            ], array_values(array_filter($mapped['lines'], fn ($l) => isset($listings[$l['variant_id']])))),
            customerId: $this->customer($mapped['retailer_id'], $mapped['retailer_name'])?->id,
            requestedDeliveryDate: $mapped['delivery_date'],
        );

        $existing = Order::where('channel', OrderChannel::Ordermentum)->where('external_ref', $mapped['external_ref'])->first();
        try {
            $order = $this->orders->upsertFromChannel($data);
        } catch (InvalidMovement $e) {
            // e.g. a cancellation for an order we never saw, or a change to a dispatched order
            return $this->finish($inbox, IntegrationInbox::STATUS_IGNORED, $e->getMessage());
        }

        // Confirmed by Ordermentum (2026-10-27): an edit adjusts Ordermentum's own stock, a
        // cancel does NOT restore it. Either way BWMS has applied the reservation delta
        // above; re-sending the absolute figure for every SKU involved puts Ordermentum right
        // (for a cancel, this is what gives the stock back).
        if ($existing) {
            $productIds = $order->lines()->pluck('product_id')->merge($existing->lines()->pluck('product_id'));
            PushOrdermentumStock::request($productIds, force: true);
        }

        return $this->finish($inbox, IntegrationInbox::STATUS_PROCESSED, null);
    }

    private function fetch(string $orderId): ?array
    {
        $started = hrtime(true);
        $log = fn (?int $status, ?string $error) => SyncAttempt::create([
            'channel' => OrderChannel::Ordermentum->value, 'operation' => 'fetch_order', 'external_id' => $orderId,
            'response_code' => $status, 'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000), 'success' => $error === null, 'error' => $error,
        ]);

        try {
            $response = $this->client->get('/v1/orders/'.rawurlencode($orderId), ['includeLines' => 'true']);
        } catch (OrdermentumException $e) {
            $log($e->status, $e->getMessage());
            throw $e;
        }

        if ($response->status() === 404) {
            $log(404, 'Not found');

            return null;
        }
        if (! $response->successful()) {
            $error = "HTTP {$response->status()}: ".mb_strimwidth($response->body(), 0, 300, '...');
            $log($response->status(), $error);
            throw $response->serverError() ? new ChannelRetryableError($error) : new OrdermentumException($error, $response->status());
        }
        $log($response->status(), null);

        return (array) $response->json();
    }

    /** @param  array{variant_id: string, sku: ?string, name: ?string}  $line */
    private function rememberUnmapped(array $line): void
    {
        $row = ChannelUnmappedVariant::firstOrNew(['channel' => OrderChannel::Ordermentum->value, 'external_variant_id' => $line['variant_id']]);
        $row->forceFill([
            'external_sku' => $line['sku'] ?? $row->external_sku,
            'name' => $line['name'] ?? $row->name,
            'occurrences' => $row->exists ? $row->occurrences + 1 : 1,
            'first_seen_at' => $row->first_seen_at ?? now(),
            'last_seen_at' => now(),
            'resolved_at' => null,
        ])->save();
    }

    /** Retailer -> customer, created on first sight so the order has someone to deliver to. */
    private function customer(?string $retailerId, ?string $name): ?Customer
    {
        if (! $retailerId) {
            return null;
        }

        return Customer::firstOrCreate(['ordermentum_retailer_id' => $retailerId], [
            'code' => 'OM-'.strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $retailerId), 0, 12)),
            'name' => $name ?: 'Ordermentum retailer '.substr($retailerId, 0, 8),
            'is_active' => true,
        ]);
    }

    private function finish(?IntegrationInbox $inbox, string $status, ?string $error): string
    {
        $inbox?->mark($status, $error);

        return $status;
    }
}
