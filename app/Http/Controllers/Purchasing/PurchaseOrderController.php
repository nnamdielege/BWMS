<?php

namespace App\Http\Controllers\Purchasing;

use App\Enums\PurchaseOrderStatus;
use App\Http\Controllers\Controller;
use App\Jobs\SendPurchaseOrderEmail;
use App\Models\Location;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderEmail;
use App\Models\PurchaseOrderLine;
use App\Models\Receipt;
use App\Models\ReceiptLine;
use App\Models\Supplier;
use App\Services\Purchasing\PurchaseOrderPdf;
use App\Services\Purchasing\PurchaseOrderService;
use App\Services\Stock\Exceptions\StockException;
use App\Support\BusinessDate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseOrderController extends Controller
{
    public function __construct(private PurchaseOrderService $orders) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:64'],
            'status' => ['nullable', Rule::in(array_merge(['open', 'all'], array_column(PurchaseOrderStatus::cases(), 'value')))],
            'supplier_id' => ['nullable', 'integer'],
        ]);
        $status = $filters['status'] ?? 'open';

        $orders = PurchaseOrder::with(['supplier:id,name', 'site:id,code,name'])
            ->when($filters['q'] ?? null, fn ($q, $t) => $q->where('number', 'like', "%{$t}%"))
            ->when($filters['supplier_id'] ?? null, fn ($q, $s) => $q->where('supplier_id', $s))
            ->when($status === 'open', fn ($q) => $q->whereIn('status', ['draft', 'sent', 'partially_received']))
            ->when(! in_array($status, ['open', 'all'], true), fn ($q) => $q->where('status', $status))
            ->latest('id')->paginate(50)->withQueryString()
            ->through(fn (PurchaseOrder $po) => [
                'id' => $po->id,
                'number' => $po->number,
                'status' => $po->status->value,
                'status_label' => $po->status->label(),
                'supplier' => $po->supplier->name,
                'site' => $po->site->name,
                'order_date' => $po->order_date->toDateString(),
                'expected_date' => $po->expected_date?->toDateString(),
                'overdue' => $po->status->canReceive() && $po->expected_date && $po->expected_date->toDateString() < BusinessDate::today(),
                'total' => $po->total,
                'legacy' => $po->legacy_id !== null,
            ]);

        return Inertia::render('purchase-orders/Index', [
            'orders' => $orders,
            'filters' => ['q' => $filters['q'] ?? '', 'status' => $status, 'supplier_id' => $filters['supplier_id'] ?? null],
            'suppliers' => Supplier::orderBy('name')->get(['id', 'name']),
            'statuses' => collect(PurchaseOrderStatus::cases())->map(fn ($s) => ['value' => $s->value, 'label' => $s->label()]),
            'canManage' => $request->user()->can('purchase_orders.manage'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('purchase-orders/Form', $this->formProps(null));
    }

    public function store(Request $request): RedirectResponse
    {
        $po = $this->guard(fn () => $this->orders->saveDraft(null, $this->validated($request), $request->user()));

        return redirect()->route('purchase-orders.show', $po)->with('success', "{$po->number} created as draft.");
    }

    public function edit(PurchaseOrder $purchaseOrder): Response|RedirectResponse
    {
        if (! $purchaseOrder->status->isEditable()) {
            return redirect()->route('purchase-orders.show', $purchaseOrder)->with('error', 'Only draft POs can be edited.');
        }

        return Inertia::render('purchase-orders/Form', $this->formProps($purchaseOrder));
    }

    public function update(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->guard(fn () => $this->orders->saveDraft($purchaseOrder, $this->validated($request), $request->user()));

        return redirect()->route('purchase-orders.show', $purchaseOrder)->with('success', "{$purchaseOrder->number} saved.");
    }

    public function show(Request $request, PurchaseOrder $purchaseOrder): Response
    {
        $po = $purchaseOrder->load(['supplier', 'site', 'lines.product:id,sku,name,base_unit,is_batch_tracked', 'lines.unit:id,name,base_qty',
            'receipts.receiver:id,name', 'receipts.lines.location.parent', 'receipts.lines.batch', 'emails.queuer:id,name', 'creator:id,name']);
        $user = $request->user();

        return Inertia::render('purchase-orders/Show', [
            'po' => [
                ...$po->only(['id', 'number', 'supplier_ref', 'notes', 'subtotal', 'tax_total', 'total', 'close_reason', 'legacy_status']),
                'status' => $po->status->value,
                'status_label' => $po->status->label(),
                'order_date' => $po->order_date->toDateString(),
                'expected_date' => $po->expected_date?->toDateString(),
                'sent_at' => $po->sent_at?->toIso8601String(),
                'supplier' => $po->supplier->only(['id', 'name', 'email', 'cc_emails', 'contact_name']),
                'site' => $po->site->name,
                'created_by' => $po->creator?->name,
                'legacy' => $po->legacy_id !== null,
                'lines' => $po->lines->map(fn (PurchaseOrderLine $l) => [
                    'id' => $l->id,
                    'product' => $l->product,
                    'unit' => $l->unit->name,
                    'base_qty_per_unit' => $l->base_qty_per_unit,
                    'qty_ordered' => $l->qty_ordered,
                    'qty_ordered_base' => $l->qty_ordered_base,
                    'qty_received_base' => $l->qty_received_base,
                    'qty_rejected_base' => $l->qty_rejected_base,
                    'unit_cost' => $l->unit_cost,
                    'tax_rate' => $l->tax_rate,
                    'line_subtotal' => $l->line_subtotal,
                    'notes' => $l->notes,
                ]),
                'receipts' => $po->receipts->map(fn (Receipt $r) => [
                    'id' => $r->id,
                    'number' => $r->number,
                    'received_at' => $r->received_at->toIso8601String(),
                    'received_by' => $r->receiver->name,
                    'supplier_ref' => $r->supplier_ref,
                    'lines' => $r->lines->map(fn (ReceiptLine $rl) => [
                        'po_line_id' => $rl->purchase_order_line_id,
                        'accepted' => $rl->qty_accepted_base,
                        'damaged' => $rl->qty_damaged_base,
                        'rejected' => $rl->qty_rejected_base,
                        'location' => $rl->location?->path(),
                        'batch' => $rl->batch?->label(),
                        'reason' => $rl->discrepancy_reason,
                        'note' => $rl->note,
                    ]),
                ]),
                'emails' => $po->emails->map(fn (PurchaseOrderEmail $e) => [
                    'id' => $e->id, 'to' => $e->to, 'cc' => $e->cc, 'status' => $e->status, 'error' => $e->error,
                    'queued_by' => $e->queuer?->name, 'created_at' => $e->created_at->toIso8601String(), 'sent_at' => $e->sent_at?->toIso8601String(),
                ]),
            ],
            'can' => [
                'manage' => $user->can('purchase_orders.manage'),
                'receive' => $user->can('receiving.perform') && $po->status->canReceive(),
                'edit' => $user->can('purchase_orders.manage') && $po->status->isEditable(),
            ],
        ]);
    }

    public function markSent(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->guard(fn () => $this->orders->markSent($purchaseOrder, $request->user()));

        return back()->with('success', "{$purchaseOrder->number} marked as sent.");
    }

    /** Queue the PO email (PDF attached by the job). A draft PO becomes "sent". */
    public function email(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $data = $request->validate([
            'to' => ['required', 'email', 'max:255'],
            'cc' => ['nullable', 'string', 'max:512'],
            'subject' => ['required', 'string', 'max:255'],
            'message' => ['nullable', 'string', 'max:5000'],
        ]);
        abort_if(in_array($purchaseOrder->status, [PurchaseOrderStatus::Cancelled], true), 422, 'Cancelled POs cannot be emailed.');

        $this->guard(fn () => $this->orders->markSent($purchaseOrder, $request->user()));
        $email = $purchaseOrder->emails()->create($data + ['queued_by' => $request->user()->id, 'status' => 'queued']);
        SendPurchaseOrderEmail::dispatch($email);

        return back()->with('success', "Email to {$data['to']} queued.");
    }

    public function retryEmail(Request $request, PurchaseOrder $purchaseOrder, PurchaseOrderEmail $email): RedirectResponse
    {
        abort_unless($email->purchase_order_id === $purchaseOrder->id, 404);
        abort_unless($email->status === 'failed', 422, 'Only failed emails can be retried.');

        $email->forceFill(['status' => 'queued', 'error' => null])->save();
        SendPurchaseOrderEmail::dispatch($email);

        return back()->with('success', 'Email re-queued.');
    }

    public function cancel(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);
        $this->guard(fn () => $this->orders->cancel($purchaseOrder, $request->user(), $data['reason'] ?? null));

        return back()->with('success', "{$purchaseOrder->number} cancelled.");
    }

    public function closeShort(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $this->guard(fn () => $this->orders->closeShort($purchaseOrder, $request->user(), $data['reason']));

        return back()->with('success', "{$purchaseOrder->number} closed short.");
    }

    public function pdf(PurchaseOrder $purchaseOrder, PurchaseOrderPdf $pdf): HttpResponse
    {
        return response($pdf->render($purchaseOrder), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$pdf->filename($purchaseOrder).'"',
        ]);
    }

    /** @return array<string, mixed> */
    private function formProps(?PurchaseOrder $po): array
    {
        $po?->load(['lines.product.units:id,product_id,name,base_qty,purchasable', 'lines.product:id,sku,name,base_unit,avg_cost']);

        return [
            'po' => $po ? [
                ...$po->only(['id', 'number', 'supplier_id', 'site_id', 'supplier_ref', 'notes']),
                'order_date' => $po->order_date->toDateString(),
                'expected_date' => $po->expected_date?->toDateString(),
                'lines' => $po->lines->map(fn (PurchaseOrderLine $l) => [
                    'product' => $l->product, 'product_unit_id' => $l->product_unit_id, 'qty_ordered' => $l->qty_ordered,
                    'unit_cost' => $l->unit_cost, 'tax_rate' => $l->tax_rate, 'notes' => $l->notes,
                ]),
            ] : null,
            'suppliers' => Supplier::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'sites' => Location::where('type', Location::TYPE_SITE)->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'today' => BusinessDate::today(),
        ];
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'site_id' => ['required', 'integer', 'exists:locations,id'],
            'order_date' => ['required', 'date'],
            'expected_date' => ['nullable', 'date', 'after_or_equal:order_date'],
            'supplier_ref' => ['nullable', 'string', 'max:64'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.product_unit_id' => ['required', 'integer', 'exists:product_units,id'],
            'lines.*.qty_ordered' => ['required', 'integer', 'min:1', 'max:1000000'],
            'lines.*.unit_cost' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'lines.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lines.*.notes' => ['nullable', 'string', 'max:255'],
        ]);
    }

    private function guard(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (StockException $e) {
            throw ValidationException::withMessages(['po' => $e->getMessage()]);
        }
    }
}
