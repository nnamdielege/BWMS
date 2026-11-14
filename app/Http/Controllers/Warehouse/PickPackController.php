<?php

namespace App\Http\Controllers\Warehouse;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\DeliveryRun;
use App\Models\Order;
use App\Models\OrderPack;
use App\Models\OrderPackDraft;
use App\Models\PickList;
use App\Models\PickListLine;
use App\Models\Setting;
use App\Services\Documents\PdfRenderer;
use App\Services\Orders\PickPackService;
use App\Services\Stock\Exceptions\StockException;
use App\Support\BusinessDate;
use App\Support\PackQty;
use App\Support\WarehouseNav;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Mobile pick & pack (Android handhelds with keyboard-wedge scanners; phone
 * camera as fallback). Queue by delivery run -> guided picking -> pack -> run
 * manifest and dispatch.
 */
class PickPackController extends Controller
{
    private const ACTIVE = [OrderStatus::Open, OrderStatus::Picking, OrderStatus::PartiallyDispatched];

    /** Enough of each order's lines to list its substitutes on a manifest. */
    private const SUB_LINES = ['lines.product:id,sku,name', 'lines.substituteFor.product:id,sku,name'];

    public function __construct(private PickPackService $service) {}

    public function queue(Request $request): Response
    {
        $today = BusinessDate::today();
        $runs = DeliveryRun::with('area')
            ->where('status', DeliveryRun::STATUS_OPEN)
            ->where(fn ($q) => $q->whereBetween('run_date', [$today, Carbon::parse($today)->addDays(7)->toDateString()])
                ->orWhereHas('orders', fn ($o) => $o->whereIn('status', self::ACTIVE)))
            ->orderBy('run_date')->orderBy('delivery_area_id')->get();

        return Inertia::render('warehouse/PickQueue', [
            'nav' => WarehouseNav::for($request, 'Pick & pack (phone/handheld)', route('dashboard')),
            'today' => $today,
            'canCancel' => $request->user()->can('orders.manage'),
            'runs' => $runs->map(function (DeliveryRun $run) {
                $lists = $run->openPickLists()->with('claimer:id,name')->withCount('lines')->get();
                $readiness = $this->service->readiness($run);
                $active = $run->orders()->whereIn('status', self::ACTIVE)->with('customer:id,name')->orderBy('id')->get();

                return [
                    'id' => $run->id,
                    'date' => $run->run_date->toDateString(),
                    'label' => $run->label(),
                    'area' => $run->area->name,
                    'waiting' => $readiness['ready']->count(),
                    'waiting_stock' => $this->service->stockRows($readiness['waiting']),
                    'active' => $active->count(),
                    'orders' => $active->map(fn (Order $o) => ['id' => $o->id, 'number' => $o->number, 'customer' => $o->customer?->name, 'channel' => $o->channel->value, 'moved_from' => $o->original_delivery_date?->toDateString(), 'hold' => $o->holdSummary()])->values(),
                    'held' => $active->where('payment_hold', true)->count(),
                    'lists' => $lists->map(fn (PickList $l) => [
                        'id' => $l->id, 'number' => $l->number, 'claimed_by' => $l->claimer?->name,
                        'mine' => $l->claimed_by === request()->user()->id, 'lines' => $l->lines_count, 'stage' => $l->stage(),
                    ]),
                ];
            })->values(),
            'unassigned' => Order::whereNull('delivery_run_id')->whereIn('status', [OrderStatus::Open, OrderStatus::PartiallyDispatched])
                ->latest('id')->limit(30)->get(['id', 'number', 'channel', 'delivery_postcode', 'delivery_suburb', 'customer_id'])->load('customer:id,name,delivery_postcode,delivery_suburb')
                ->map(fn (Order $o) => ['id' => $o->id, 'number' => $o->number, 'customer' => $o->customer?->name, 'channel' => $o->channel->value,
                    'postcode' => $o->delivery_postcode ?? $o->customer?->delivery_postcode, 'suburb' => $o->delivery_suburb ?? $o->customer?->delivery_suburb]),
        ]);
    }

    public function start(Request $request, DeliveryRun $run): RedirectResponse
    {
        $list = $this->guard(fn () => $this->service->startNew($run, $request->user()));

        return redirect()->route('pick.work', $list);
    }

    public function claim(Request $request, PickList $pickList): RedirectResponse
    {
        $this->guard(fn () => $this->service->claim($pickList, $request->user(), $request->boolean('take_over')));

        return redirect()->route('pick.work', $pickList);
    }

    public function work(Request $request, PickList $pickList): Response
    {
        $pickList->load(['deliveryRun.area', 'claimer:id,name']);

        return Inertia::render('warehouse/PickWork', [
            'nav' => WarehouseNav::for($request, collect([$pickList->number, $pickList->deliveryRun?->label()])->filter()->implode(' · '), route('pick.queue')),
            'pickList' => [
                'id' => $pickList->id, 'number' => $pickList->number, 'status' => $pickList->status,
                'run' => $pickList->deliveryRun?->label(), 'run_id' => $pickList->delivery_run_id,
                'claimed_by' => $pickList->claimer?->name, 'mine' => $pickList->claimed_by === $request->user()->id,
            ],
            'lines' => $this->service->walkOrder($pickList)->map(fn (PickListLine $l) => $this->lineRow($l))->values(),
            'overrideReasons' => PickPackService::OVERRIDE_REASONS,
            'requireBinScan' => Setting::requiresBinScan(),
            // Managers can substitute a short pick there and then.
            'canSubstitute' => $request->user()->can('orders.manage'),
        ]);
    }

    public function record(Request $request, PickList $pickList, PickListLine $line): RedirectResponse
    {
        abort_unless($line->pick_list_id === $pickList->id, 404);
        $data = $request->validate([
            ...PackQty::rules(),
            'bin_barcode' => ['nullable', 'string', 'max:64'],
            'product_barcodes' => ['array', 'max:500'],
            'product_barcodes.*' => ['string', 'max:64'],
            'override_reason' => ['nullable', Rule::in(PickPackService::OVERRIDE_REASONS)],
        ]);
        if (PackQty::isBlank($data)) {
            throw ValidationException::withMessages(['qty' => 'Enter how many you picked.']);
        }
        // Typed in the order line's pack plus loose units, saved as base units.
        $qtyBase = PackQty::toBase($data, $line->orderLine->base_qty_per_unit);
        $this->guard(fn () => $this->service->recordLine($line, $request->user(), $qtyBase,
            $data['bin_barcode'] ?? null, array_values($data['product_barcodes'] ?? []), $data['override_reason'] ?? null));

        return back();
    }

    public function packPage(Request $request, PickList $pickList): Response
    {
        $lines = $this->service->walkOrder($pickList);
        $packs = OrderPack::where('pick_list_id', $pickList->id)->with('packer:id,name')->get()->keyBy('order_id');
        $pickList->load('deliveryRun.area');
        $drafts = OrderPackDraft::where('pick_list_id', $pickList->id)->pluck('cartons', 'order_id');

        return Inertia::render('warehouse/Pack', [
            'nav' => WarehouseNav::for($request, "Pack {$pickList->number}", route('pick.work', $pickList)),
            'pickList' => ['id' => $pickList->id, 'number' => $pickList->number, 'status' => $pickList->status, 'run_id' => $pickList->delivery_run_id, 'run' => $pickList->deliveryRun?->label()],
            'orders' => $lines->groupBy(fn ($l) => $l->orderLine->order_id)->map(function ($group) use ($packs, $drafts) {
                $order = $group->first()->orderLine->order;
                $pack = $packs[$order->id] ?? null;

                return [
                    'id' => $order->id, 'number' => $order->number, 'customer' => $order->customer?->name, 'channel' => $order->channel->value,
                    'picking_done' => $group->every(fn ($l) => $l->qty_picked_base !== null),
                    'lines' => $group->map(fn (PickListLine $l) => $this->lineRow($l))->values(),
                    'cartons' => $pack?->cartons ?? $drafts[$order->id] ?? null,
                    'pack' => $pack ? ['cartons' => $pack->cartons, 'packed_by' => $pack->packer->name, 'packed_at' => $pack->packed_at->toIso8601String()] : null,
                ];
            })->values(),
        ]);
    }

    /** Called in the background as the pack screen changes, so it answers without a page visit. */
    public function progress(Request $request, PickList $pickList): HttpResponse
    {
        $data = $request->validate([
            'ticked' => ['array', 'max:500'],
            'ticked.*' => ['integer'],
            'unticked' => ['array', 'max:500'],
            'unticked.*' => ['integer'],
            'cartons' => ['array', 'max:500'],
            'cartons.*' => ['nullable', 'integer', 'min:0', 'max:500'],
        ]);
        $this->guard(fn () => $this->service->saveProgress($pickList, $request->user(), $data['ticked'] ?? [], $data['unticked'] ?? [], $data['cartons'] ?? []));

        return response()->noContent();
    }

    public function pack(Request $request, PickList $pickList, Order $order): RedirectResponse
    {
        $data = $request->validate([
            'cartons' => ['required', 'integer', 'min:0', 'max:500'],
        ]);
        $this->guard(fn () => $this->service->pack($pickList, $order, $request->user(), (int) $data['cartons']));

        return back()->with('success', "{$order->number} packed.");
    }

    public function slip(PickList $pickList, Order $order, PdfRenderer $pdf): HttpResponse
    {
        $order->load(['customer', 'deliveryRun.area']);
        $lines = $pickList->lines()->whereHas('orderLine', fn ($q) => $q->where('order_id', $order->id))
            ->with(['product', 'orderLine.unit', 'orderLine.substituteFor.product:id,sku,name'])->get()->groupBy('order_line_id');
        abort_if($lines->isEmpty(), 404);

        $rows = $lines->map(function ($group) {
            $ol = $group->first()->orderLine;
            $per = max(1, $ol->base_qty_per_unit);
            $toPick = (int) $group->sum('qty_to_pick_base');
            $picked = (int) $group->sum('qty_picked_base');

            return [
                'sku' => $group->first()->product->sku, 'name' => $group->first()->product->name, 'chilled' => $group->first()->product->is_chilled,
                'pack' => $ol->unit->name, 'ordered' => $this->packs($toPick, $per), 'packed' => $this->packs($picked, $per),
                'short' => $picked < $toPick ? $this->packs($toPick - $picked, $per) : null,
                'sub_label' => $ol->subLabel(),
            ];
        })->values();

        $body = $pdf->render('pdf.packing-slip', [
            'order' => $order, 'rows' => $rows, 'company' => Setting::all_(),
            'pack' => OrderPack::where('order_id', $order->id)->where('pick_list_id', $pickList->id)->with('packer')->first(),
        ], "Packing slip {$order->number}", ['format' => 'A5']);

        return response($body, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="packing-slip-'.$order->number.'.pdf"']);
    }

    public function manifest(Request $request, DeliveryRun $run): Response
    {
        $run->load('area');
        $orders = $run->orders()->with(['customer', 'packs', ...self::SUB_LINES])->orderBy('id')->get();

        return Inertia::render('warehouse/RunManifest', [
            'nav' => WarehouseNav::for($request, $run->label(), route('pick.queue')),
            'run' => ['id' => $run->id, 'label' => $run->label(), 'status' => $run->status, 'date' => $run->run_date->toDateString(), 'trip' => $run->trip_label],
            'orders' => $orders->map(fn (Order $o) => $this->manifestRow($o)),
            'openLists' => $run->openPickLists()->get(['id', 'number']),
        ]);
    }

    /** The day for the driver: runs grouped by trip label, each area its own trip without one. Only the grouping is shared. */
    public function trips(Request $request, string $date): Response
    {
        $runs = DeliveryRun::with(['area:id,name,sort_order', 'orders' => fn ($q) => $q->whereIn('status', [...self::ACTIVE, OrderStatus::Dispatched])->with(['customer', 'packs', ...self::SUB_LINES])->orderBy('id')])
            ->whereDate('run_date', $date)->get()->sortBy('area.sort_order')->filter(fn (DeliveryRun $r) => $r->orders->isNotEmpty());
        $label = Carbon::parse($date)->format('D j M');

        return Inertia::render('warehouse/TripManifest', [
            'nav' => WarehouseNav::for($request, "Trips · {$label}", route('pick.queue')),
            'label' => $label,
            'trips' => $runs->groupBy(fn (DeliveryRun $r) => $r->trip_label ?: $r->area->name)->map(fn ($group, $trip) => [
                'label' => $trip,
                'orders' => $group->sum(fn (DeliveryRun $r) => $r->orders->count()),
                'cartons' => (int) $group->sum(fn (DeliveryRun $r) => $r->orders->sum(fn (Order $o) => $o->packs->sum('cartons'))),
                'runs' => $group->map(fn (DeliveryRun $r) => [
                    'id' => $r->id, 'area' => $r->area->name, 'status' => $r->status, 'orders' => $r->orders->map(fn (Order $o) => $this->manifestRow($o))->values(),
                ])->values(),
            ])->values(),
        ]);
    }

    private function manifestRow(Order $o): array
    {
        return [
            'id' => $o->id, 'number' => $o->number, 'status' => $o->status->value, 'status_label' => $o->status->label(),
            'customer' => $o->customer?->name, 'channel' => $o->channel->value, 'moved_from' => $o->original_delivery_date?->toDateString(), 'hold' => $o->holdSummary(),
            'address' => trim(($o->delivery_street ?? $o->customer?->delivery_street).' '.($o->delivery_suburb ?? $o->customer?->delivery_suburb).' '.($o->delivery_postcode ?? $o->customer?->delivery_postcode)),
            'cartons' => (int) $o->packs->sum('cartons'), 'packed' => $o->packs->isNotEmpty(),
            // So the driver knows what to tell the customer.
            'substitutions' => $o->lines->filter(fn ($l) => $l->substitute_for_line_id !== null && $l->qty_base > $l->qty_cancelled_base)
                ->map(fn ($l) => "{$l->product->sku} {$l->product->name}: ".$l->subLabel())->values(),
        ];
    }

    public function dispatchRun(Request $request, DeliveryRun $run): RedirectResponse
    {
        $this->guard(fn () => $this->service->dispatchRun($run, $request->user()));

        return redirect()->route('pick.manifest', $run)->with('success', 'Run dispatched.');
    }

    /** @return array<string, mixed> */
    private function lineRow(PickListLine $l): array
    {
        $ol = $l->orderLine;
        $per = max(1, $ol->base_qty_per_unit);

        return [
            'id' => $l->id,
            'order_line_id' => $ol->id,
            'sub_label' => $ol->subLabel(),
            'order' => ['id' => $ol->order_id, 'number' => $ol->order->number, 'customer' => $ol->order->customer?->name, 'channel' => $ol->order->channel->value],
            'no_stock' => $l->no_stock,
            'bin' => $l->location ? ['code' => $l->location->code, 'path' => $l->location->path(), 'barcode' => $l->location->barcode] : null,
            'product' => ['sku' => $l->product->sku, 'name' => $l->product->name, 'chilled' => $l->product->is_chilled, 'base_unit' => $l->product->base_unit],
            'pack' => PackQty::forInput($per, $ol->unit->name, $l->product->base_unit),
            'barcodes' => $l->product->units->flatMap(fn ($u) => $u->barcodes->map(fn ($b) => ['code' => $b->barcode, 'base_qty' => $u->base_qty]))->values(),
            'batch' => $l->batch ? ['lot' => $l->batch->lot_code, 'best_before' => $l->batch->best_before?->toDateString()] : null,
            'to_pick_base' => $l->qty_to_pick_base,
            'to_pick_label' => PackQty::label($l->qty_to_pick_base, $per, $ol->unit->name, $l->product->base_unit),
            'picked_base' => $l->qty_picked_base,
            'ticked' => $l->ticked_at !== null,
            'scanned' => (bool) ($l->bin_scanned_at && $l->product_scanned_at),
            'override_reason' => $l->override_reason,
            // For a manager's substitute on a short pick; pickers don't see prices.
            'unit_price' => request()->user()?->can('orders.manage') ? $ol->unit_price : null,
        ];
    }

    /** "3" packs, or "2 + 5" (packs + base units) when FEFO split a pack across batches. */
    private function packs(int $base, int $per): string
    {
        if ($per <= 1 || $base % $per === 0) {
            return (string) intdiv($base, max(1, $per));
        }

        return intdiv($base, $per).' + '.($base % $per);
    }

    private function guard(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (StockException $e) {
            throw ValidationException::withMessages(['pick' => $e->getMessage()]);
        }
    }
}
