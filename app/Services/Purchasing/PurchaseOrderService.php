<?php

namespace App\Services\Purchasing;

use App\Enums\PurchaseOrderStatus;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\Stock\Exceptions\InvalidMovement;
use App\Support\Audit;
use App\Support\NumberSequence;
use Illuminate\Support\Facades\DB;

/**
 * PO lifecycle: draft -> sent -> partially_received -> received,
 * or closed (short) / cancelled. Totals are always computed here, never
 * taken from the client (legacy H-11).
 */
class PurchaseOrderService
{
    /**
     * @param  array{supplier_id:int, site_id:int, order_date:string, expected_date?:?string, supplier_ref?:?string, notes?:?string,
     *               lines: list<array{product_id:int, product_unit_id:int, qty_ordered:int, unit_cost:string|float, tax_rate?:string|float, notes?:?string}>}  $data
     */
    public function saveDraft(?PurchaseOrder $po, array $data, User $actor): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $data, $actor) {
            if ($po) {
                $po = PurchaseOrder::lockForUpdate()->findOrFail($po->id);
                $this->assert($po->status->isEditable(), "{$po->number} is {$po->status->label()} and can no longer be edited.");
            } else {
                $po = new PurchaseOrder;
                $po->forceFill([
                    'number' => NumberSequence::next('purchase_order', 'PO'),
                    'status' => PurchaseOrderStatus::Draft,
                    'created_by' => $actor->id,
                ]);
            }

            $site = Location::findOrFail($data['site_id']);
            $this->assert($site->type === Location::TYPE_SITE, 'Deliver-to must be a site.');

            $po->fill($data)->save();

            $po->lines()->delete();
            foreach (array_values($data['lines']) as $i => $line) {
                $product = Product::findOrFail($line['product_id']);
                $unit = $product->units()->whereKey($line['product_unit_id'])->firstOrFail();
                $this->assert($unit->purchasable, "{$product->sku}: pack \"{$unit->name}\" is not purchasable.");

                $qty = (int) $line['qty_ordered'];
                $cost = number_format((float) $line['unit_cost'], 4, '.', '');
                $po->lines()->create([
                    'product_id' => $product->id,
                    'product_unit_id' => $unit->id,
                    'qty_ordered' => $qty,
                    'base_qty_per_unit' => $unit->base_qty,
                    'qty_ordered_base' => $unit->toBase($qty),
                    'unit_cost' => $cost,
                    'tax_rate' => $line['tax_rate'] ?? 10,
                    'line_subtotal' => bcmul($cost, (string) $qty, 2),
                    'notes' => $line['notes'] ?? null,
                    'sort_order' => $i,
                ]);
            }

            return $this->recalculateTotals($po);
        });
    }

    public function recalculateTotals(PurchaseOrder $po): PurchaseOrder
    {
        $subtotal = '0';
        $tax = '0';
        foreach ($po->lines()->get() as $line) {
            $subtotal = bcadd($subtotal, (string) $line->line_subtotal, 2);
            $tax = bcadd($tax, bcdiv(bcmul((string) $line->line_subtotal, (string) $line->tax_rate, 4), '100', 4), 4);
        }
        $tax = number_format((float) $tax, 2, '.', '');
        $po->forceFill(['subtotal' => $subtotal, 'tax_total' => $tax, 'total' => bcadd($subtotal, $tax, 2)])->save();

        return $po->refresh();
    }

    public function markSent(PurchaseOrder $po, User $actor): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $actor) {
            $po = PurchaseOrder::lockForUpdate()->findOrFail($po->id);
            if ($po->status !== PurchaseOrderStatus::Draft) {
                return $po; // already sent: idempotent
            }
            $this->assert($po->lines()->exists(), 'Add at least one line before sending.');
            $po->forceFill(['status' => PurchaseOrderStatus::Sent, 'sent_at' => now(), 'sent_by' => $actor->id])->save();

            return $po;
        });
    }

    public function cancel(PurchaseOrder $po, User $actor, ?string $reason): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $actor, $reason) {
            $po = PurchaseOrder::lockForUpdate()->findOrFail($po->id);
            $this->assert(in_array($po->status, [PurchaseOrderStatus::Draft, PurchaseOrderStatus::Sent], true) && ! $po->receipts()->exists(),
                'Only a draft or sent PO with nothing received can be cancelled. Close it short instead.');
            $po->forceFill(['status' => PurchaseOrderStatus::Cancelled, 'closed_at' => now(), 'closed_by' => $actor->id, 'close_reason' => $reason])->save();

            return $po;
        });
    }

    /** Close a partially received PO: the outstanding quantity will not arrive. */
    public function closeShort(PurchaseOrder $po, User $actor, string $reason): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $actor, $reason) {
            $po = PurchaseOrder::lockForUpdate()->findOrFail($po->id);
            $this->assert($po->status === PurchaseOrderStatus::PartiallyReceived, 'Only a partially received PO can be closed short.');
            $outstanding = $po->lines()->get()->sum(fn ($l) => $l->outstandingBase());
            $po->forceFill(['status' => PurchaseOrderStatus::Closed, 'closed_at' => now(), 'closed_by' => $actor->id, 'close_reason' => $reason])->save();
            Audit::record('purchase_order_closed_short', $po, meta: ['outstanding_base_units' => $outstanding, 'reason' => $reason]);

            return $po;
        });
    }

    private function assert(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new InvalidMovement($message);
        }
    }
}
