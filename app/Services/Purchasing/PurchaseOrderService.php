<?php

namespace App\Services\Purchasing;

use App\Enums\PurchaseOrderStatus;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchasingBudget;
use App\Models\Setting;
use App\Models\User;
use App\Services\Stock\Exceptions\InvalidMovement;
use App\Support\Audit;
use App\Support\BusinessDate;
use App\Support\NumberSequence;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * PO lifecycle: suggested/draft -> approved -> sent -> partially_received -> received -> paid,
 * or closed (short) / cancelled. Approval checks the week's purchasing budget; a PO can't be
 * sent until it's approved. Totals are always computed here, never taken from the client (legacy H-11).
 */
class PurchaseOrderService
{
    public function __construct(private PurchasingBudgetService $budgets) {}

    /**
     * $status sets the status: a new PO is a draft unless told otherwise, and an existing one keeps
     * its status (a person saving a suggested PO passes Draft, so the next suggestion run leaves it be).
     * $actor is null for the scheduled suggestion run.
     *
     * @param  array{supplier_id:int, site_id:int, order_date:string, expected_date?:?string, supplier_ref?:?string, notes?:?string,
     *               lines: list<array{product_id:int, product_unit_id:int, qty_ordered:int, unit_cost:string|float, tax_rate?:string|float, notes?:?string}>}  $data
     */
    public function saveDraft(?PurchaseOrder $po, array $data, ?User $actor, ?PurchaseOrderStatus $status = null): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $data, $actor, $status) {
            if ($po) {
                $po = PurchaseOrder::lockForUpdate()->findOrFail($po->id);
                $this->assert($po->status->isEditable(), "{$po->number} is {$po->status->label()} and can no longer be edited.");
                if ($status) {
                    $po->forceFill(['status' => $status]);
                }
            } else {
                $po = new PurchaseOrder;
                $po->forceFill([
                    'number' => $this->newNumber(),
                    'status' => $status ?? PurchaseOrderStatus::Draft,
                    'created_by' => $actor?->id,
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
                    // Given by the buyer, else the product's default: 0% GST-free, 10% otherwise (suggested POs).
                    'tax_rate' => $line['tax_rate'] ?? $product->defaultTaxRate(),
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

    /**
     * Approve a suggested or draft PO against this week's budget (Brisbane Mon–Sun, ex GST). Over
     * budget, or with no budget set, it needs $override and a reason, which is kept on the PO. Sets
     * the payment due date: expected delivery (else today + lead time) + the supplier's payment terms.
     * The approver may be whoever raised it.
     */
    public function approve(PurchaseOrder $po, User $actor, bool $override = false, ?string $reason = null): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $actor, $override, $reason) {
            $po = PurchaseOrder::with('supplier')->lockForUpdate()->findOrFail($po->id);
            if ($po->status === PurchaseOrderStatus::Approved) {
                return $po; // double submit
            }
            $this->assert($po->status->canApprove(), "{$po->number} is {$po->status->label()} and can't be approved.");
            $this->assert($po->lines()->exists(), 'Add at least one line before approving.');

            // Serialises approvals in the same week, so two can't both fit the last of the budget.
            PurchasingBudget::whereDate('week_start', $this->budgets->weekStart())->lockForUpdate()->first();
            $check = $this->budgets->check($po);
            $reason = trim((string) $reason) ?: null;
            if ($check['over'] && ! ($override && $reason)) {
                throw new OverBudget($check);
            }

            $today = BusinessDate::today();
            $lead = $po->supplier->lead_time_days ?? Setting::reorder()['default_lead_days'];
            $delivery = $po->expected_date ? $po->expected_date->toDateString() : Carbon::parse($today)->addDays($lead)->toDateString();
            $po->forceFill([
                'status' => PurchaseOrderStatus::Approved,
                'approved_at' => now(),
                'approved_by' => $actor->id,
                'override_reason' => $check['over'] ? $reason : null,
                'payment_due_date' => Carbon::parse($delivery)->addDays((int) $po->supplier->payment_terms_days)->toDateString(),
            ])->save();
            Audit::record('purchase_order_approved', $po, meta: [
                'subtotal' => $check['po_total'], 'budget' => $check['budget'], 'committed_before' => $check['committed'],
                'remaining_before' => $check['remaining'], 'over_budget' => $check['over'], 'override_reason' => $check['over'] ? $reason : null,
            ]);

            return $po;
        });
    }

    public function markSent(PurchaseOrder $po, User $actor): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $actor) {
            $po = PurchaseOrder::lockForUpdate()->findOrFail($po->id);
            $this->assert(! $po->status->canApprove(), "Approve {$po->number} before sending it.");
            if ($po->status !== PurchaseOrderStatus::Approved) {
                return $po; // already sent: idempotent
            }
            $po->forceFill(['status' => PurchaseOrderStatus::Sent, 'sent_at' => now(), 'sent_by' => $actor->id])->save();

            return $po;
        });
    }

    /**
     * The supplier has been paid (in Xero); recorded by hand. Approved, sent, part received, received
     * or closed short. paid_at marks it paid; the status only becomes "paid" once it's fully received
     * (here, or later in receiving), so a pre-paid PO can still be sent and received.
     */
    public function markPaid(PurchaseOrder $po, User $actor): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $actor) {
            $po = PurchaseOrder::lockForUpdate()->findOrFail($po->id);
            if ($po->paid_at !== null) {
                return $po;
            }
            $this->assert(in_array($po->status, PurchaseOrderStatus::PAYABLE, true),
                "{$po->number} is {$po->status->label()}: only an approved, sent, received or closed PO can be marked paid.");
            $po->forceFill([
                'paid_at' => now(),
                'paid_by' => $actor->id,
                'status' => $po->status === PurchaseOrderStatus::Received ? PurchaseOrderStatus::Paid : $po->status,
            ])->save();
            Audit::record('purchase_order_paid', $po, meta: ['total' => (string) $po->total]);

            return $po;
        });
    }

    public function cancel(PurchaseOrder $po, ?User $actor, ?string $reason): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $actor, $reason) {
            $po = PurchaseOrder::lockForUpdate()->findOrFail($po->id);
            $this->assert(in_array($po->status, [PurchaseOrderStatus::Suggested, PurchaseOrderStatus::Draft, PurchaseOrderStatus::Approved, PurchaseOrderStatus::Sent], true)
                && ! $po->receipts()->exists(),
                'Only a PO with nothing received can be cancelled. Close it short instead.');
            $po->forceFill(['status' => PurchaseOrderStatus::Cancelled, 'closed_at' => now(), 'closed_by' => $actor?->id, 'close_reason' => $reason])->save();

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

    /**
     * PO-YYYYMMDD-NNNN (Brisbane date the PO is created, restarting at 0001 each day). Existing POs keep
     * their numbers; a number already taken (POs imported from legacy BWMS used the same format) is skipped.
     */
    private function newNumber(): string
    {
        do {
            $number = NumberSequence::daily('purchase_order', 'PO');
        } while (PurchaseOrder::where('number', $number)->exists());

        return $number;
    }

    private function assert(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new InvalidMovement($message);
        }
    }
}
