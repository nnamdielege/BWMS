<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseOrderLine;
use App\Models\Range;
use App\Models\Setting;
use App\Models\Supplier;
use App\Services\Purchasing\PurchaseOrderService;
use App\Services\Purchasing\ReorderPlanner;
use App\Services\Stock\Exceptions\StockException;
use App\Support\Audit;
use App\Support\BusinessDate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Purchasing → Reorder suggestions: what to order, per supplier. Draft POs only; nothing is sent. */
class ReorderController extends Controller
{
    public function __construct(private ReorderPlanner $planner) {}

    public function index(Request $request): Response
    {
        $f = $request->validate([
            'supplier_id' => ['nullable', 'integer'],
            'range_id' => ['nullable', 'integer'],
            'only_needed' => ['nullable', 'boolean'],
            'stockout_only' => ['nullable', 'boolean'],
        ]);
        $filters = [
            'supplier_id' => isset($f['supplier_id']) ? (int) $f['supplier_id'] : null,
            'range_id' => isset($f['range_id']) ? (int) $f['range_id'] : null,
            'only_needed' => (bool) ($f['only_needed'] ?? true),
            'stockout_only' => (bool) ($f['stockout_only'] ?? false),
        ];

        return Inertia::render('purchasing/Reorder', [
            'filters' => $filters,
            'plan' => $this->planner->build($filters),
            'suppliers' => Supplier::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'ranges' => Range::orderBy('name')->get(['id', 'name']),
            'can' => ['order' => $request->user()->can('purchase_orders.manage')],
        ]);
    }

    public function settings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'safety_mode' => ['required', Rule::in(['days', 'z'])],
            'safety_days' => ['required', 'numeric', 'min:0', 'max:60'],
            'safety_z' => ['required', 'numeric', 'min:0', 'max:5'],
            'default_lead_days' => ['required', 'integer', 'min:0', 'max:365'],
            'default_review_days' => ['required', 'integer', 'min:0', 'max:90'],
            'daily_email' => ['boolean'],
        ]);
        Setting::put([
            Setting::REORDER_SAFETY_MODE => $data['safety_mode'],
            Setting::REORDER_SAFETY_DAYS => (string) $data['safety_days'],
            Setting::REORDER_SAFETY_Z => (string) $data['safety_z'],
            Setting::REORDER_DEFAULT_LEAD_DAYS => (string) $data['default_lead_days'],
            Setting::REORDER_DEFAULT_REVIEW_DAYS => (string) $data['default_review_days'],
            Setting::REORDER_EMAIL => ($data['daily_email'] ?? false) ? '1' : '0',
        ]);
        Audit::record('reorder_settings_changed', null, new: $data);

        return back()->with('success', 'Reorder settings saved.');
    }

    /** A draft PO for one supplier from the quantities on screen, in the product's purchase pack. */
    public function draft(Request $request, PurchaseOrderService $pos): RedirectResponse
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')->where('is_active', true)],
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.product_id' => ['required', 'integer', 'distinct', 'exists:products,id'],
            'lines.*.qty_base' => ['required', 'integer', 'min:0', 'max:10000000'],
        ]);
        $supplier = Supplier::findOrFail($data['supplier_id']);
        $site = Location::where('type', Location::TYPE_SITE)->where('is_active', true)->orderBy('id')->firstOrFail();
        $products = Product::with('units')->whereIn('id', array_column($data['lines'], 'product_id'))->get()->keyBy('id');

        $lines = [];
        foreach ($data['lines'] as $line) {
            if ((int) $line['qty_base'] <= 0) {
                continue;
            }
            $product = $products[$line['product_id']];
            $buyable = $product->units->where('purchasable', true);
            $unit = ($product->order_multiple_base ? $buyable->firstWhere('base_qty', $product->order_multiple_base) : null)
                ?? $buyable->firstWhere('default_purchase', true) ?? $buyable->sortByDesc('base_qty')->first();
            if (! $unit) {
                throw ValidationException::withMessages(['reorder' => "{$product->sku} has no pack it can be bought in."]);
            }
            $last = PurchaseOrderLine::where('product_id', $product->id)->where('product_unit_id', $unit->id)
                ->whereHas('purchaseOrder', fn ($q) => $q->where('status', '!=', 'cancelled'))->latest('id')->value('unit_cost');
            $lines[] = [
                'product_id' => $product->id,
                'product_unit_id' => $unit->id,
                'qty_ordered' => (int) ceil($line['qty_base'] / max(1, $unit->base_qty)),
                'unit_cost' => $last ?? number_format((float) $product->avg_cost * $unit->base_qty, 4, '.', ''),
            ];
        }
        if ($lines === []) {
            throw ValidationException::withMessages(['reorder' => 'Nothing to order: every quantity is 0.']);
        }

        $today = BusinessDate::today();
        try {
            $po = $pos->saveDraft(null, [
                'supplier_id' => $supplier->id, 'site_id' => $site->id, 'order_date' => $today,
                'expected_date' => $supplier->lead_time_days !== null ? Carbon::parse($today)->addDays($supplier->lead_time_days)->toDateString() : null,
                'notes' => 'From reorder suggestions.',
                'lines' => $lines,
            ], $request->user());
        } catch (StockException $e) {
            throw ValidationException::withMessages(['reorder' => $e->getMessage()]);
        }
        Audit::record('reorder_draft_po_created', $po, meta: ['supplier' => $supplier->code, 'lines' => count($lines)]);

        return redirect()->route('purchase-orders.edit', $po)->with('success', "Draft {$po->number} created for {$supplier->name}. Check it, then send it from here.");
    }
}
