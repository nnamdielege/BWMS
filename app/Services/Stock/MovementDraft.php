<?php

namespace App\Services\Stock;

use App\Enums\MovementType;
use App\Models\Batch;
use App\Models\Location;
use App\Models\Product;
use App\Models\ReasonCode;
use DateTimeInterface;

/**
 * A movement to be posted. The idempotency key must be deterministic for the
 * business event, e.g. "adj:{client-uuid}" or "om:order:{id}:line:{n}:sale".
 */
final class MovementDraft
{
    public function __construct(
        public readonly Product $product,
        public readonly Location $location,
        public readonly int $qty,
        public readonly MovementType $type,
        public readonly string $idempotencyKey,
        public readonly ?Batch $batch = null,
        public readonly ?ReasonCode $reason = null,
        public readonly ?string $sourceType = null,
        public readonly ?int $sourceId = null,
        public readonly ?string $unitCost = null,
        public readonly ?DateTimeInterface $occurredAt = null,
        public readonly ?string $note = null,
        public readonly bool $allowNegative = false,
    ) {}

    public function batchKey(): int
    {
        return $this->batch?->id ?? 0;
    }

    /** Hash of the business content, used to detect a key reused for different data. */
    public function payloadHash(): string
    {
        return hash('sha256', implode('|', [
            $this->product->id, $this->location->id, $this->batchKey(), $this->qty, $this->type->value,
            $this->reason?->id, $this->sourceType, $this->sourceId,
        ]));
    }
}
