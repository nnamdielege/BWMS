<?php

namespace App\Services\Purchasing;

use App\Enums\PurchaseOrderStatus;
use App\Models\Location;
use App\Models\PurchaseOrder;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Stock\Exceptions\InvalidMovement;
use App\Support\BusinessDate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Turns reorder suggestions (ReorderPlanner) into POs: one supplier's draft from the Reorder
 * page, or the weekly run of suggested POs (purchasing:suggest and the button on the PO list).
 */
class ReorderService
{
    public function __construct(private ReorderPlanner $planner, private PurchaseOrderService $pos) {}

    /**
     * A PO for one supplier from base-unit quantities, delivered to the first active site, expected
     * today + lead time. With $into, that (suggested) PO's lines are replaced instead.
     *
     * @param  list<array{product_id: int, qty_base: int}>  $qtyLines
     */
    public function draftPurchaseOrder(Supplier $supplier, array $qtyLines, ?User $actor, PurchaseOrderStatus $status = PurchaseOrderStatus::Draft,
        ?PurchaseOrder $into = null, string $notes = 'From reorder suggestions.'): PurchaseOrder
    {
        $lines = $this->planner->draftLines($qtyLines);
        if ($lines === []) {
            throw new InvalidMovement('Nothing to order: every quantity is 0.');
        }
        $siteId = $into?->site_id ?? Location::where('type', Location::TYPE_SITE)->where('is_active', true)->orderBy('id')->firstOrFail()->id;
        $today = BusinessDate::today();

        return $this->pos->saveDraft($into, [
            'supplier_id' => $supplier->id, 'site_id' => $siteId, 'order_date' => $today,
            'expected_date' => $this->expectedDate($supplier, $today),
            'notes' => $notes,
            'lines' => $lines,
        ], $actor, $status);
    }

    /** Delivery from an order placed on $date: the supplier's lead time, else the reorder settings' default. */
    public function expectedDate(Supplier $supplier, string $date): string
    {
        return Carbon::parse($date)->addDays($supplier->lead_time_days ?? Setting::reorder()['default_lead_days'])->toDateString();
    }

    /**
     * One suggested PO per supplier for every auto-reorder product the planner says to order.
     * Idempotent: a supplier's existing suggested PO has its lines replaced (its own quantities
     * aren't counted as on order meanwhile), and one whose supplier no longer needs anything is
     * cancelled. Drafts and approved POs are never touched; they count as on order. A PO under
     * the supplier's minimum order is flagged, not held back.
     *
     * @return Collection<int, array{po: PurchaseOrder, action: string, below_minimum: bool, min_order_value: ?float}>
     */
    public function draftPurchaseOrders(?User $actor = null): Collection
    {
        $lock = Cache::lock('purchasing:suggest', 300);
        if (! $lock->get()) {
            throw new InvalidMovement('Suggestions are already being generated. Try again in a minute.');
        }
        try {
            return $this->run($actor);
        } finally {
            $lock->release();
        }
    }

    private function run(?User $actor): Collection
    {
        $plan = $this->planner->build(['only_needed' => true, 'exclude_suggested' => true]);
        $wanted = [];
        foreach ($plan['groups'] as $group) {
            if (! $group['supplier']) {
                continue; // no supplier known: nothing to raise a PO against
            }
            $lines = collect($group['lines'])->filter(fn ($l) => $l['auto_reorder'] && $l['suggest_base'] > 0)
                ->map(fn ($l) => ['product_id' => $l['product_id'], 'qty_base' => $l['suggest_base']])->values()->all();
            if ($lines !== []) {
                $wanted[$group['supplier']['id']] = $lines;
            }
        }

        $suppliers = Supplier::whereIn('id', array_keys($wanted))->where('is_active', true)->get()->keyBy('id');
        $existing = PurchaseOrder::where('status', PurchaseOrderStatus::Suggested)->orderBy('id')->get()->groupBy('supplier_id');
        $notes = 'Suggested from sales on '.Carbon::parse(BusinessDate::today())->format('D j M Y').'. Refreshed by each suggestion run until approved or edited.';

        $results = collect();
        foreach ($wanted as $supplierId => $lines) {
            $supplier = $suppliers[$supplierId] ?? null;
            if (! $supplier) {
                continue; // inactive supplier
            }
            $current = $existing->pull($supplierId) ?? collect();
            $into = $current->shift();
            foreach ($current as $duplicate) {
                $this->pos->cancel($duplicate, $actor, 'Duplicate suggested PO for the same supplier.');
            }
            $po = $this->draftPurchaseOrder($supplier, $lines, $actor, PurchaseOrderStatus::Suggested, $into, $notes);
            $min = $supplier->min_order_value !== null ? (float) $supplier->min_order_value : null;
            $results->push([
                'po' => $po,
                'action' => $into ? 'updated' : 'created',
                'below_minimum' => $min !== null && (float) $po->subtotal < $min,
                'min_order_value' => $min,
            ]);
        }

        // Suppliers that no longer need anything (or are inactive): their suggestion is stale.
        foreach ($existing->flatten(1) as $stale) {
            $this->pos->cancel($stale, $actor, 'Nothing to order at the latest suggestion run.');
            $results->push(['po' => $stale->refresh(), 'action' => 'cancelled', 'below_minimum' => false, 'min_order_value' => null]);
        }

        return $results;
    }
}
