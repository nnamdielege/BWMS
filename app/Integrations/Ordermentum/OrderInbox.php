<?php

namespace App\Integrations\Ordermentum;

use App\Enums\OrderChannel;
use App\Jobs\ProcessOrdermentumOrder;
use App\Models\IntegrationInbox;
use Illuminate\Support\Facades\DB;

/**
 * Stores an inbound order hint (webhook or poll) and queues processing.
 * Ordermentum sends no event ID, so the dedupe key is derived from
 * event + order id + updatedAt (B3.2); a redelivery is stored once.
 */
class OrderInbox
{
    public const ORDER_EVENTS = ['order_created', 'order_updated', 'poll'];

    /** @return IntegrationInbox|null null when this exact event was already stored */
    public function record(string $event, ?string $orderId, ?string $updatedAt, string $rawPayload, array $headers = [], ?string $signature = null): ?IntegrationInbox
    {
        $dedupe = hash('sha256', implode('|', [$event, $orderId, $updatedAt ?? hash('sha256', $rawPayload)]));
        $isOrder = in_array($event, self::ORDER_EVENTS, true) && filled($orderId);
        $status = match (true) {
            ! $isOrder => IntegrationInbox::STATUS_IGNORED,
            ! config('bwms.ordermentum.ingest_enabled') => IntegrationInbox::STATUS_HELD,
            default => IntegrationInbox::STATUS_RECEIVED,
        };

        $inserted = DB::table('integration_inbox')->insertOrIgnore([
            'channel' => OrderChannel::Ordermentum->value,
            'event' => mb_substr($event, 0, 64),
            'external_id' => $orderId ? mb_substr($orderId, 0, 64) : null,
            'dedupe_key' => $dedupe,
            'status' => $status,
            'headers' => json_encode($headers),
            'signature_status' => $signature,
            'payload' => $rawPayload,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        if ($inserted === 0) {
            return null;
        }

        $inbox = IntegrationInbox::where('dedupe_key', $dedupe)->first();
        if ($status === IntegrationInbox::STATUS_RECEIVED) {
            ProcessOrdermentumOrder::dispatch($orderId, $inbox->id);
        }

        return $inbox;
    }
}
