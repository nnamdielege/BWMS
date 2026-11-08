<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\ReceiptLine;
use App\Services\Purchasing\ReceivingService;
use App\Services\Stock\Exceptions\StockException;
use App\Services\Stock\StockQuery;
use App\Support\PackQty;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ReceiptController extends Controller
{
    public function __construct(private ReceivingService $receiving, private StockQuery $stock) {}

    public function create(PurchaseOrder $purchaseOrder): Response|RedirectResponse
    {
        if (! $purchaseOrder->status->canReceive()) {
            return redirect()->route('purchase-orders.show', $purchaseOrder)->with('error', "{$purchaseOrder->status->label()} POs cannot be received.");
        }
        $purchaseOrder->load(['supplier:id,name', 'site', 'lines.product:id,sku,name,base_unit,is_batch_tracked', 'lines.unit:id,name,base_qty']);
        $bins = Location::bins()->whereIn('id', $this->stock->binIdsUnder($purchaseOrder->site))->where('is_active', true)
            ->where('is_quarantine', false)->with('parent.parent')->orderByDesc('is_receiving')->orderBy('sort_order')->get();

        return Inertia::render('purchase-orders/Receive', [
            'po' => [
                'id' => $purchaseOrder->id,
                'number' => $purchaseOrder->number,
                'supplier' => $purchaseOrder->supplier->name,
                'site' => $purchaseOrder->site->name,
                'lines' => $purchaseOrder->lines->map(fn (PurchaseOrderLine $l) => [
                    'id' => $l->id,
                    'product' => $l->product,
                    'unit' => $l->unit->name,
                    'base_qty_per_unit' => $l->base_qty_per_unit,
                    'qty_ordered_base' => $l->qty_ordered_base,
                    'outstanding_base' => $l->outstandingBase(),
                ]),
            ],
            'defaultBinId' => $this->receiving->landingBin($purchaseOrder->site)?->id,
            'bins' => $bins->map(fn (Location $b) => ['id' => $b->id, 'label' => $b->path(), 'receiving' => $b->is_receiving]),
            'reasons' => ReceiptLine::REASONS,
            'clientToken' => (string) Str::uuid(),
            'canOverReceive' => request()->user()->can('purchase_orders.manage'),
        ]);
    }

    public function store(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $data = $request->validate([
            'client_token' => ['required', 'uuid'],
            'supplier_ref' => ['nullable', 'string', 'max:64'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'allow_over_receipt' => ['boolean'],
            'lines' => ['required', 'array', 'min:1', 'max:400'],
            'lines.*.purchase_order_line_id' => ['required', 'integer'],
            // Each in the PO line's pack plus loose units; saved as base units below.
            'lines.*.accepted' => ['nullable', 'array'], ...PackQty::rules('lines.*.accepted.'),
            'lines.*.damaged' => ['nullable', 'array'], ...PackQty::rules('lines.*.damaged.'),
            'lines.*.rejected' => ['nullable', 'array'], ...PackQty::rules('lines.*.rejected.'),
            'lines.*.location_id' => ['nullable', 'integer'],
            'lines.*.lot_code' => ['nullable', 'string', 'max:64'],
            'lines.*.best_before' => ['nullable', 'date'],
            'lines.*.discrepancy_reason' => ['nullable', Rule::in(ReceiptLine::REASONS)],
            'lines.*.note' => ['nullable', 'string', 'max:255'],
        ]);

        $allowOver = (bool) ($data['allow_over_receipt'] ?? false);
        if ($allowOver && ! $request->user()->can('purchase_orders.manage')) {
            throw ValidationException::withMessages(['receipt' => 'Only purchasing managers can accept more than ordered.']);
        }

        $per = $purchaseOrder->lines()->pluck('base_qty_per_unit', 'id');
        $lines = array_map(function (array $line) use ($per) {
            $perPack = (int) ($per[$line['purchase_order_line_id']] ?? 1);
            foreach (['accepted', 'damaged', 'rejected'] as $kind) {
                $line["qty_{$kind}_base"] = PackQty::toBase($line[$kind] ?? [], $perPack);
                unset($line[$kind]);
            }

            return $line;
        }, $data['lines']);

        try {
            $receipt = $this->receiving->receive(
                $purchaseOrder, $request->user(), $lines, $data['client_token'],
                $data['supplier_ref'] ?? null, $data['notes'] ?? null, $allowOver,
            );
        } catch (StockException $e) {
            throw ValidationException::withMessages(['receipt' => $e->getMessage()]);
        }

        return redirect()->route('purchase-orders.show', $purchaseOrder)->with('success', "Receipt {$receipt->number} posted.");
    }
}
