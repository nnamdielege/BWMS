<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\SupplierCredit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Purchasing → Supplier credits: stock returned from quarantine, and the credit still owed for it. */
class SupplierCreditController extends Controller
{
    public function index(Request $request): Response
    {
        $data = $request->validate(['status' => ['nullable', Rule::in(['open', 'received', 'all'])]]);
        $status = $data['status'] ?? 'open';
        $tz = config('bwms.business_timezone');
        $today = now($tz)->startOfDay();

        $rows = SupplierCredit::query()
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->with(['supplier:id,name', 'product' => fn ($q) => $q->withTrashed()->select(['id', 'sku', 'name', 'base_unit']),
                'batch:id,lot_code', 'creator:id,name', 'receiver:id,name'])
            ->orderBy('created_at')->orderBy('id')->get()
            ->map(fn (SupplierCredit $c) => [
                'id' => $c->id,
                'date' => $c->created_at->setTimezone($tz)->toDateString(),
                'age_days' => (int) $c->created_at->setTimezone($tz)->startOfDay()->diffInDays($today),
                'supplier_id' => $c->supplier_id,
                'supplier' => $c->supplier->name,
                'sku' => $c->product?->sku,
                'name' => $c->product?->name,
                'qty' => $c->qty_base,
                'base_unit' => $c->product?->base_unit,
                'batch' => $c->batch?->lot_code,
                'amount' => (float) $c->amount,
                'no_cost' => $c->unit_cost === null,
                'status' => $c->status,
                'note' => $c->note,
                'credit_ref' => $c->credit_ref,
                'by' => $c->creator?->name,
                'received_at' => $c->received_at?->setTimezone($tz)->toDateString(),
                'received_by' => $c->receiver?->name,
            ])->values();

        return Inertia::render('purchasing/SupplierCredits', [
            'status' => $status,
            'rows' => $rows,
            'by_supplier' => $rows->groupBy('supplier_id')->map(fn ($g) => [
                'supplier' => $g[0]['supplier'], 'count' => $g->count(), 'amount' => round($g->sum('amount'), 2), 'oldest_days' => $g->max('age_days'),
            ])->sortByDesc('amount')->values(),
            'total' => round($rows->sum('amount'), 2),
            'can' => ['manage' => $request->user()->can('purchase_orders.manage')],
        ]);
    }

    public function receive(Request $request, SupplierCredit $credit): RedirectResponse
    {
        $data = $request->validate(['credit_ref' => ['nullable', 'string', 'max:64']]);
        if ($credit->status !== SupplierCredit::STATUS_RECEIVED) {
            $credit->update([
                'status' => SupplierCredit::STATUS_RECEIVED,
                'credit_ref' => $data['credit_ref'] ?? null,
                'received_at' => now(),
                'received_by' => $request->user()->id,
            ]);
        }

        return back()->with('success', 'Credit marked received.');
    }
}
