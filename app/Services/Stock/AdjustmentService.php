<?php

namespace App\Services\Stock;

use App\Models\Batch;
use App\Models\Location;
use App\Models\Product;
use App\Models\ReasonCode;
use App\Models\StockMovement;
use App\Models\User;

/**
 * Manual adjustments and write-offs. The movement type comes from the reason
 * code. The client token (a UUID generated per form) makes double submits safe.
 */
class AdjustmentService
{
    public function __construct(private StockLedger $ledger) {}

    public function adjust(User $actor, Product $product, Location $bin, int $qty, ReasonCode $reason, ?string $note, string $clientToken, ?Batch $batch = null): StockMovement
    {
        return $this->ledger->postOne(new MovementDraft(
            product: $product,
            location: $bin,
            qty: $qty,
            type: $reason->movement_type,
            idempotencyKey: "adj:{$clientToken}",
            batch: $batch,
            reason: $reason,
            sourceType: 'adjustment',
            note: $note,
        ), $actor);
    }
}
