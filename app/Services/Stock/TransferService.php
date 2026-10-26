<?php

namespace App\Services\Stock;

use App\Enums\MovementType;
use App\Models\Batch;
use App\Models\Location;
use App\Models\Product;
use App\Models\User;
use App\Services\Stock\Exceptions\InvalidMovement;
use Illuminate\Support\Collection;

/**
 * Bin-to-bin moves: a transfer_out and transfer_in posted atomically.
 */
class TransferService
{
    public function __construct(private StockLedger $ledger) {}

    public function transfer(User $actor, Product $product, Location $from, Location $to, int $qty, string $clientToken, ?Batch $batch = null, ?string $note = null): Collection
    {
        if ($qty <= 0) {
            throw new InvalidMovement('Transfer quantity must be positive.');
        }
        if ($from->is($to)) {
            throw new InvalidMovement('Choose two different bins.');
        }

        $draft = fn (Location $bin, int $signedQty, MovementType $type, string $suffix) => new MovementDraft(
            product: $product,
            location: $bin,
            qty: $signedQty,
            type: $type,
            idempotencyKey: "xfer:{$clientToken}:{$suffix}",
            batch: $batch,
            sourceType: 'transfer',
            note: $note,
        );

        return $this->ledger->post([
            $draft($from, -$qty, MovementType::TransferOut, 'out'),
            $draft($to, $qty, MovementType::TransferIn, 'in'),
        ], $actor);
    }
}
