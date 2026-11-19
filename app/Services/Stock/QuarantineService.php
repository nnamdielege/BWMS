<?php

namespace App\Services\Stock;

use App\Enums\MovementType;
use App\Models\Batch;
use App\Models\Location;
use App\Models\Product;
use App\Models\QuarantineEntry;
use App\Models\ReasonCode;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierCredit;
use App\Models\User;
use App\Services\Stock\Exceptions\InvalidMovement;
use Illuminate\Support\Collection;

/**
 * Stock into and out of quarantine bins. Going in needs a reason (kept in
 * quarantine_entries); coming out is a write-off, a return to the supplier (which
 * opens a supplier credit at cost) or a release back to a sellable bin.
 *
 * Each action posts its movements first (idempotent on the client token) and then
 * records its row keyed by the movement, so a retry after a failure finishes the job
 * without posting twice.
 */
class QuarantineService
{
    public function __construct(private StockLedger $ledger, private TransferService $transfers) {}

    /** Bin to quarantine bin, with the reason it's going there. */
    public function moveIn(User $actor, Product $product, Location $from, Location $to, int $qty, string $reason, string $clientToken, ?Batch $batch = null, ?string $note = null): Collection
    {
        $this->assert($to->is_quarantine, "{$to->code} is not a quarantine bin.");
        $this->assert(isset(QuarantineEntry::REASONS[$reason]), 'Choose why the stock is going into quarantine.');

        $movements = $this->transfers->transfer($actor, $product, $from, $to, $qty, $clientToken, $batch, $note);
        self::record($movements->firstWhere('type', MovementType::TransferIn), $reason, $note, $actor);

        return $movements;
    }

    /** Remember why a movement put stock into quarantine. Safe to call again for the same movement. */
    public static function record(StockMovement $in, string $reason, ?string $note, ?User $actor): QuarantineEntry
    {
        return QuarantineEntry::firstOrCreate(['stock_movement_id' => $in->id], [
            'product_id' => $in->product_id,
            'location_id' => $in->location_id,
            'batch_key' => $in->batch_id ?? 0,
            'reason' => $reason,
            'note' => $note,
            'created_by' => $actor?->id,
        ]);
    }

    public function writeOff(User $actor, Product $product, Location $bin, int $qty, ReasonCode $reason, string $clientToken, ?Batch $batch = null, ?string $note = null): StockMovement
    {
        $this->assertOut($bin, $qty);
        $this->assert($reason->movement_type === MovementType::WriteOff, 'Choose a write-off reason.');

        return $this->ledger->postOne(new MovementDraft(
            product: $product,
            location: $bin,
            qty: -$qty,
            type: MovementType::WriteOff,
            idempotencyKey: "qwo:{$clientToken}",
            batch: $batch,
            reason: $reason,
            sourceType: 'quarantine',
            unitCost: $product->costAtNow(),
            note: $note,
        ), $actor);
    }

    /** Out to the supplier; the credit expected is the quantity at today's average cost. */
    public function returnToSupplier(User $actor, Product $product, Location $bin, int $qty, Supplier $supplier, string $clientToken, ?Batch $batch = null, ?string $note = null): SupplierCredit
    {
        $this->assertOut($bin, $qty);
        $cost = $product->costAtNow();

        $movement = $this->ledger->postOne(new MovementDraft(
            product: $product,
            location: $bin,
            qty: -$qty,
            type: MovementType::ReturnToSupplier,
            idempotencyKey: "qrts:{$clientToken}",
            batch: $batch,
            sourceType: 'quarantine',
            unitCost: $cost,
            note: trim("{$supplier->name}. ".($note ?? '')),
        ), $actor);

        return SupplierCredit::firstOrCreate(['stock_movement_id' => $movement->id], [
            'supplier_id' => $supplier->id,
            'product_id' => $product->id,
            'batch_id' => $batch?->id,
            'qty_base' => $qty,
            'unit_cost' => $cost,
            'amount' => round($qty * (float) $cost, 2),
            'note' => $note,
            'created_by' => $actor->id,
        ]);
    }

    /** Back to a sellable bin. Not for stock past its best-before, and only with a reason. */
    public function release(User $actor, Product $product, Location $bin, Location $to, int $qty, string $note, string $clientToken, ?Batch $batch = null): Collection
    {
        $this->assertOut($bin, $qty);
        $this->assert(trim($note) !== '', 'Say why the stock is fit to sell again.');
        $this->assert(! $batch?->isExpired(), "Batch {$batch?->lot_code} is past its best-before; it can't go back on sale.");
        $this->assert($to->isBin() && $to->is_active && $to->is_sellable && ! $to->is_quarantine, "{$to->code} is not a sellable bin.");

        return $this->transfers->transfer($actor, $product, $bin, $to, $qty, $clientToken, $batch, 'Released from quarantine: '.trim($note));
    }

    private function assertOut(Location $bin, int $qty): void
    {
        $this->assert($bin->is_quarantine, "{$bin->code} is not a quarantine bin.");
        $this->assert($qty > 0, 'Enter a quantity.');
    }

    private function assert(bool $ok, string $message): void
    {
        if (! $ok) {
            throw new InvalidMovement($message);
        }
    }
}
