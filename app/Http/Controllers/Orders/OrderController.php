<?php

namespace App\Http\Controllers\Orders;

use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\DeliveryArea;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderLine;
use App\Services\Delivery\DeliveryScheduler;
use App\Services\Orders\OrderService;
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

class OrderController extends Controller
{
    public function __construct(private OrderService $orders) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:64'],
            'status' => ['nullable', Rule::in(array_merge(['active', 'all'], array_column(OrderStatus::cases(), 'value')))],
            'channel' => ['nullable', Rule::enum(OrderChannel::class)],
            'flag' => ['nullable', Rule::in(['oversold', 'review'])],
        ]);
        $status = $filters['status'] ?? 'active';

        $orders = Order::with(['customer:id,name', 'site:id,name'])
            ->withSum('lines as qty_base_total', 'qty_base')
            ->when($filters['q'] ?? null, fn ($q, $t) => $q->where(fn ($w) => $w->where('number', 'like', "%{$t}%")->orWhere('external_ref', 'like', "%{$t}%")))
            ->when($filters['channel'] ?? null, fn ($q, $c) => $q->where('channel', $c))
            ->when(($filters['flag'] ?? null) === 'oversold', fn ($q) => $q->where('oversold', true))
            ->when(($filters['flag'] ?? null) === 'review', fn ($q) => $q->where('needs_review', true))
            ->when($status === 'active', fn ($q) => $q->whereIn('status', ['open', 'picking', 'partially_dispatched']))
            ->when(! in_array($status, ['active', 'all'], true), fn ($q) => $q->where('status', $status))
            ->orderByRaw('requested_delivery_date IS NULL, requested_delivery_date')->latest('id')
            ->paginate(50)->withQueryString()
            ->through(fn (Order $o) => [
                'id' => $o->id, 'number' => $o->number, 'channel' => $o->channel->value, 'external_ref' => $o->external_ref,
                'customer' => $o->customer?->name, 'site' => $o->site->name, 'site_id' => $o->site_id,
                'status' => $o->status->value, 'status_label' => $o->status->label(),
                'oversold' => $o->oversold, 'needs_review' => $o->needs_review,
                'requested_delivery_date' => $o->requested_delivery_date?->toDateString(),
                'created_at' => $o->created_at->toIso8601String(),
            ]);

        return Inertia::render('orders/Index', [
            'orders' => $orders,
            'filters' => ['q' => $filters['q'] ?? '', 'status' => $status, 'channel' => $filters['channel'] ?? '', 'flag' => $filters['flag'] ?? ''],
            'statuses' => collect(OrderStatus::cases())->map(fn ($s) => ['value' => $s->value, 'label' => $s->label()]),
            'can' => ['manage' => $request->user()->can('orders.manage'), 'pick' => $request->user()->can('picking.perform')],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('orders/Form', [
            'customers' => Customer::where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']),
            'sites' => Location::where('type', Location::TYPE_SITE)->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'today' => BusinessDate::today(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'site_id' => ['required', 'integer', 'exists:locations,id'],
            'requested_delivery_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'short_ship_policy' => ['required', Rule::in(['backorder', 'cancel'])],
            'allow_backorder' => ['boolean'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.product_unit_id' => ['required', 'integer', 'exists:product_units,id'],
            'lines.*.qty' => ['required', 'integer', 'min:1', 'max:1000000'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
        ]);

        try {
            $order = $this->orders->createManual($request->user(), $data, (bool) ($data['allow_backorder'] ?? false));
        } catch (StockException $e) {
            throw ValidationException::withMessages(['order' => $e->getMessage()]);
        }

        return redirect()->route('orders.show', $order)->with('success', "{$order->number} created and stock reserved.");
    }

    public function show(Request $request, Order $order): Response
    {
        $order->load(['deliveryRun.area', 'customer', 'site', 'lines.product:id,sku,name,base_unit', 'lines.unit:id,name,base_qty', 'lines.reservation', 'lines.pickLines.pickList:id,number,status', 'creator:id,name']);

        return Inertia::render('orders/Show', [
            'order' => [
                ...$order->only(['id', 'number', 'external_ref', 'external_version', 'oversold', 'needs_review', 'short_ship_policy', 'notes', 'cancel_reason']),
                'channel' => $order->channel->value,
                'status' => $order->status->value,
                'status_label' => $order->status->label(),
                'customer' => $order->customer?->only(['id', 'name', 'code']),
                'site' => $order->site->name,
                'requested_delivery_date' => $order->requested_delivery_date?->toDateString(),
                'run' => $order->deliveryRun ? ['id' => $order->deliveryRun->id, 'label' => $order->deliveryRun->label(), 'area_id' => $order->deliveryRun->delivery_area_id, 'date' => $order->deliveryRun->run_date->toDateString()] : null,
                'run_assignment' => $order->run_assignment,
                'delivery_address' => trim(implode(' ', array_filter([$order->delivery_street ?? $order->customer?->delivery_street, $order->delivery_suburb ?? $order->customer?->delivery_suburb, $order->delivery_postcode ?? $order->customer?->delivery_postcode]))),
                'oversold_at' => $order->oversold_at?->toIso8601String(),
                'created_at' => $order->created_at->toIso8601String(),
                'created_by' => $order->creator?->name,
                'lines' => $order->lines->map(fn (OrderLine $l) => [
                    'id' => $l->id,
                    'line_ref' => $l->line_ref,
                    'product' => $l->product,
                    'unit' => $l->unit->name,
                    'base_qty_per_unit' => $l->base_qty_per_unit,
                    'qty' => $l->qty,
                    'qty_base' => $l->qty_base,
                    'reserved' => $l->reservation?->qty_base ?? 0,
                    'dispatched' => $l->qty_dispatched_base,
                    'cancelled' => $l->qty_cancelled_base,
                    'unit_price' => $l->unit_price,
                    'pick_lists' => $l->pickLines->map(fn ($p) => $p->pickList->only(['id', 'number', 'status']))->unique('id')->values(),
                ]),
            ],
            'can' => ['manage' => $request->user()->can('orders.manage') && $order->status->isActive()],
            'deliveryAreas' => DeliveryArea::where('is_active', true)->orderBy('sort_order')->get(['id', 'name']),
        ]);
    }

    /** Staff choose the run (area + date) by hand; it sticks across later channel updates. */
    public function assignRun(Request $request, Order $order, DeliveryScheduler $scheduler): RedirectResponse
    {
        $data = $request->validate([
            'delivery_area_id' => ['required', 'integer', Rule::exists('delivery_areas', 'id')],
            'run_date' => ['required', 'date_format:Y-m-d'],
        ]);
        if (! $order->status->isActive()) {
            throw ValidationException::withMessages(['run' => "{$order->number} is {$order->status->label()}."]);
        }
        $scheduler->assignManually($order, DeliveryArea::findOrFail($data['delivery_area_id']), Carbon::parse($data['run_date'], config('bwms.business_timezone')));
        Audit::record('order_run_assigned', $order, meta: $data);

        return back()->with('success', 'Run set.');
    }

    public function cancel(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        try {
            $this->orders->cancel($order, $request->user(), $data['reason']);
        } catch (StockException $e) {
            throw ValidationException::withMessages(['order' => $e->getMessage()]);
        }

        return back()->with('success', "{$order->number} cancelled; reserved stock released.");
    }

    /** Acknowledge an oversold / needs-review flag after a human has dealt with it. */
    public function clearFlags(Order $order): RedirectResponse
    {
        $order->forceFill(['oversold' => false, 'needs_review' => false])->save();

        return back()->with('success', 'Flags cleared.');
    }
}
