<?php

namespace App\Services\Orders;

use App\Enums\OrderChannel;

/**
 * Full state of an order as a channel sees it. Every channel update is a
 * full-state upsert (portal contract B4.4; Ordermentum ingestion stub).
 */
final class ChannelOrderData
{
    /**
     * @param  list<array{line_ref: string, product_id: int, product_unit_id: int, qty: int, unit_price?: ?string}>  $lines
     */
    public function __construct(
        public readonly OrderChannel $channel,
        public readonly string $externalRef,
        public readonly int $version,
        public readonly string $status,          // confirmed | cancelled
        public readonly int $siteId,
        public readonly array $lines,
        public readonly ?int $customerId = null,
        public readonly ?string $requestedDeliveryDate = null,
        public readonly ?string $notes = null,
    ) {}
}
