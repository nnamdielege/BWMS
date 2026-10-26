<?php

namespace App\Services\Purchasing;

use App\Enums\MovementType;
use App\Enums\PurchaseOrderStatus;
use App\Models\Batch;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Receipt;
use App\Models\ReceiptLine;
use App\Models\StockBalance;
use App\Models\User;
use App\Services\Stock\Exceptions\InvalidMovement;
use App\Services\Stock\MovementDraft;
use App\Services\Stock\StockLedger;
use App\Services\Stock\StockQuery;
use App\Support\NumberSequence;
use Illuminate\Support\Facades\DB;

/**
 * Receive goods against a PO.
 *
 * Per receipt line (several lines may reference one PO line, e.g. two lots):
 *  - accepted -> `receipt` movement into the chosen bin (default: the site's landing bin, see landingBin())
 *  - damaged  -> `receipt` movement into the site's quarantine bin (counted, never sellable)
 *  - rejected -> not stocked; recorded as a discrepancy (goods went back)
 *
 * Over-receipt (accepted + damaged beyond outstanding) needs $allowOver.
 * Weighted average cost is updated from the PO line cost. The client token
 * makes a double submit return the original receipt.
 */
class ReceivingService
{
    public function __construct(private StockLedger $ledger, private StockQuery $stock) {}

    /**
     * Where accepted stock lands by default: the site's configured bin if it is
     * still an active, non-quarantine bin at that site; otherwise the first
     * sellable bin; otherwise a receiving bin.
     */
    public function landingBin(Location $site): ?Location
    {
        $siteBins = $this->stock->binIdsUnder($site);
        $usable = fn () => Location::bins()->whereIn('id', $siteBins)->where('is_active', true)->where('is_quarantine', false);

        return ($site->receive_location_id ? $usable()->whereKey($site->receive_location_id)->first() : null)
            ?? $usable()->where('is_sellable', true)->orderBy('sort_order')->orderBy('id')->first()
            ?? $usable()->where('is_receiving', true)->orderBy('sort_order')->orderBy('id')->first();
    }

    /**
     * @param  list<array{purchase_order_line_id:int, qty_accepted_base?:int, qty_damaged_base?:int, qty_rejected_base?:int,
     *                    location_id?:?int, lot_code?:?string, best_before?:?string, discrepancy_reason?:?string, note?:?string}>  $lines
     */
    public function receive(PurchaseOrder $po, User $actor, array $lines, string $clientToken, ?string $supplierRef = null, ?string $notes = null, bool $allowOver = false): Receipt
    {
        if ($existing = Receipt::where('client_token', $clientToken)->first()) {
            return $existing; // double submit / retry
        }

        return DB::transaction(function () use ($po, $actor, $lines, $clientToken, $supplierRef, $notes, $allowOver) {
            $po = PurchaseOrder::lockForUpdate()->with('site')->findOrFail($po->id);
            $this->assert($po->status->canReceive(), "{$po->number} is {$po->status->label()}; it must be sent before receiving.");

            $poLines = $po->lines()->with('product')->get()->keyBy('id');
            $siteBins = $this->stock->binIdsUnder($po->site);
            $quarantine = Location::bins()->whereIn('id', $siteBins)->where('is_quarantine', true)->where('is_active', true)->orderBy('sort_order')->first();
            $defaultBin = $this->landingBin($po->site);

            // Validate everything before writing anything.
            $planned = [];
            $incoming = [];
            foreach ($lines as $i => $row) {
                $poLine = $poLines[$row['purchase_order_line_id']] ?? null;
                $this->assert($poLine !== null, "Line {$i}: not part of {$po->number}.");

                $accepted = (int) ($row['qty_accepted_base'] ?? 0);
                $damaged = (int) ($row['qty_damaged_base'] ?? 0);
                $rejected = (int) ($row['qty_rejected_base'] ?? 0);
                if ($accepted + $damaged + $rejected === 0) {
                    continue;
                }
                $this->assert(min($accepted, $damaged, $rejected) >= 0, 'Quantities cannot be negative.');

                $sku = $poLine->product->sku;
                $bin = $accepted > 0 ? (isset($row['location_id']) ? Location::find($row['location_id']) : $defaultBin) : null;
                if ($accepted > 0) {
                    $this->assert($bin && $bin->isBin() && in_array($bin->id, $siteBins, true), "{$sku}: choose a bin at {$po->site->name} for accepted stock.");
                }
                if ($damaged > 0) {
                    $this->assert($quarantine !== null, "{$po->site->name} has no quarantine bin for damaged stock.");
                }

                $reason = $row['discrepancy_reason'] ?? null;
                $this->assert($reason === null || in_array($reason, ReceiptLine::REASONS, true), "{$sku}: unknown discrepancy reason.");
                if ($rejected > 0 && ! $reason) {
                    throw new InvalidMovement("{$sku}: give a reason for the rejected quantity.");
                }
                if ($damaged > 0 && ! $reason) {
                    $reason = 'damaged';
                }

                $batch = null;
                if ($poLine->product->is_batch_tracked && $accepted + $damaged > 0) {
                    $this->assert(! empty($row['lot_code']) && ! empty($row['best_before']), "{$sku} is batch-tracked: enter lot and best-before.");
                    $batch = Batch::findOrCreateFor($poLine->product, $row['lot_code'], $row['best_before']);
                    if (! $batch->supplier_id) {
                        $batch->forceFill(['supplier_id' => $po->supplier_id, 'received_on' => now()->toDateString()])->save();
                    }
                }

                $incoming[$poLine->id] = ($incoming[$poLine->id] ?? 0) + $accepted + $damaged;
                $planned[] = compact('poLine', 'accepted', 'damaged', 'rejected', 'bin', 'batch', 'reason') + ['note' => $row['note'] ?? null];
            }
            $this->assert($planned !== [], 'Enter at least one received, damaged or rejected quantity.');

            $overLineIds = [];
            $overLabels = [];
            foreach ($incoming as $lineId => $qty) {
                $poLine = $poLines[$lineId];
                if ($qty > $poLine->outstandingBase()) {
                    $overLineIds[] = $lineId;
                    $overLabels[] = "{$poLine->product->sku} (+".($qty - $poLine->outstandingBase()).')';
                }
            }
            if ($overLineIds !== [] && ! $allowOver) {
                throw new InvalidMovement('More than ordered: '.implode(', ', $overLabels).'. Confirm the over-receipt to continue.');
            }

            $receipt = Receipt::create([
                'number' => NumberSequence::next('receipt', 'GRN'),
                'purchase_order_id' => $po->id,
                'site_id' => $po->site_id,
                'received_at' => now(),
                'received_by' => $actor->id,
                'supplier_ref' => $supplierRef,
                'notes' => $notes,
                'client_token' => $clientToken,
            ]);

            // Weighted average cost must use on-hand BEFORE this receipt; lock products in id order.
            $products = Product::whereIn('id', collect($planned)->pluck('poLine.product_id')->unique()->sort()->values())
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $costInputs = [];

            $drafts = [];
            foreach ($planned as $p) {
                /** @var PurchaseOrderLine $poLine */
                $poLine = $p['poLine'];
                $line = $receipt->lines()->create([
                    'purchase_order_line_id' => $poLine->id,
                    'product_id' => $poLine->product_id,
                    'qty_accepted_base' => $p['accepted'],
                    'qty_damaged_base' => $p['damaged'],
                    'qty_rejected_base' => $p['rejected'],
                    'location_id' => $p['bin']?->id,
                    'quarantine_location_id' => $p['damaged'] > 0 ? $quarantine->id : null,
                    'batch_id' => $p['batch']?->id,
                    'discrepancy_reason' => $p['reason'] ?? (in_array($poLine->id, $overLineIds, true) ? 'over' : null),
                    'note' => $p['note'],
                ]);

                $cost = $poLine->unitCostPerBase();
                $draft = fn (Location $bin, int $qty, string $suffix) => new MovementDraft(
                    product: $products[$poLine->product_id],
                    location: $bin,
                    qty: $qty,
                    type: MovementType::Receipt,
                    idempotencyKey: "rcpt:{$receipt->id}:{$line->id}:{$suffix}",
                    batch: $p['batch'],
                    sourceType: 'receipt',
                    sourceId: $receipt->id,
                    unitCost: $cost,
                    note: $po->number,
                );
                if ($p['accepted'] > 0) {
                    $drafts[] = $draft($p['bin'], $p['accepted'], 'good');
                }
                if ($p['damaged'] > 0) {
                    $drafts[] = $draft($quarantine, $p['damaged'], 'damaged');
                }

                $costInputs[$poLine->product_id][] = [$p['accepted'] + $p['damaged'], $cost];
                $poLine->increment('qty_received_base', $p['accepted'] + $p['damaged']);
                if ($p['rejected'] > 0) {
                    $poLine->increment('qty_rejected_base', $p['rejected']);
                }
            }

            foreach ($costInputs as $productId => $inputs) {
                $this->updateAverageCost($products[$productId], $inputs);
            }
            if ($drafts !== []) {
                $this->ledger->post($drafts, $actor);
            }

            $this->refreshStatus($po);

            return $receipt;
        });
    }

    /**
     * new_avg = (max(on_hand_before, 0) * avg + sum(qty * cost)) / (max(on_hand_before, 0) + sum(qty))
     *
     * @param  list<array{0:int, 1:string}>  $inputs  [qty_base, cost_per_base]
     */
    public function updateAverageCost(Product $product, array $inputs): void
    {
        $qty = array_sum(array_column($inputs, 0));
        if ($qty <= 0) {
            return;
        }
        // Locking read: the REPEATABLE READ snapshot may predate concurrent movements.
        $onHand = max(0, (int) StockBalance::where('product_id', $product->id)->sharedLock()->sum('on_hand'));
        $value = bcmul((string) $onHand, (string) $product->avg_cost, 4);
        foreach ($inputs as [$q, $c]) {
            $value = bcadd($value, bcmul((string) $q, $c, 4), 4);
        }
        $product->forceFill(['avg_cost' => bcdiv($value, (string) ($onHand + $qty), 4)])->save();
    }

    private function refreshStatus(PurchaseOrder $po): void
    {
        $lines = $po->lines()->get();
        $complete = $lines->every(fn (PurchaseOrderLine $l) => $l->qty_received_base >= $l->qty_ordered_base);
        $any = $lines->contains(fn (PurchaseOrderLine $l) => $l->qty_received_base > 0 || $l->qty_rejected_base > 0);

        $po->forceFill(['status' => $complete ? PurchaseOrderStatus::Received : ($any ? PurchaseOrderStatus::PartiallyReceived : $po->status)])->save();
    }

    private function assert(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new InvalidMovement($message);
        }
    }
}
