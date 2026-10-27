<?php

namespace App\Http\Controllers\Warehouse;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\DeliveryRun;
use App\Models\Order;
use App\Models\OrderPack;
use App\Models\PickList;
use App\Models\PickListLine;
use App\Models\Setting;
use App\Services\Documents\PdfRenderer;
use App\Services\Orders\PickPackService;
use App\Services\Stock\Exceptions\StockException;
use App\Support\BusinessDate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
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
    public function __construct(private PickPackService $service) {}

    public function queue(Request $request): Response
    {
        $today = BusinessDate::today();
        $runs = DeliveryRun::with('area')
            ->where('status', DeliveryRun::STATUS_OPEN)
            ->where(fn ($q) => $q->whereBetween('run_date', [now()->subDays(7)->toDateString(), now()->addDays(7)->toDateString()])
                ->orWhereHas('orders', fn ($o) => $o->whereIn('status', [OrderStatus::Open, OrderStatus::Picking, OrderStatus::PartiallyDispatched])))
            ->orderBy('run_date')->orderBy('delivery_area_id')->get();

        return Inertia::render('warehouse/PickQueue', [
            'today' => $today,
            'runs' => $runs->map(function (DeliveryRun $run) {
                $lists = $run->pickLists()->where('status', PickList::STATUS_OPEN)->with('claimer:id,name')->withCount([
                    'lines', 'lines as lines_done_count' => fn ($q) => $q->whereNotNull('qty_picked_base'),
                ])->get();

                return [
                    'id' => $run->id,
                    'date' => $run->run_date->toDateString(),
                    'label' => $run->label(),
                    'area' => $run->area->name,
                    'waiting' => $this->service->waitingOrders($run)->count(),
                    'active' => $run->orders()->whereIn('status', [OrderStatus::Open, OrderStatus::Picking, OrderStatus::PartiallyDispatched])->count(),
                    'lists' => $lists->map(fn (PickList $l) => [
                        'id' => $l->id, 'number' => $l->number, 'claimed_by' => $l->claimer?->name,
                        'mine' => $l->claimed_by === request()->user()->id, 'lines' => $l->lines_count, 'done' => $l->lines_done_count,
                    ]),
                ];
            })->values(),
            'unassigned' => Order::whereNull('delivery_run_id')->whereIn('status', [OrderStatus::Open, OrderStatus::PartiallyDispatched])
                ->latest('id')->limit(30)->get(['id', 'number', 'delivery_postcode', 'delivery_suburb', 'customer_id'])->load('customer:id,name,delivery_postcode,delivery_suburb')
                ->map(fn (Order $o) => ['id' => $o->id, 'number' => $o->number, 'customer' => $o->customer?->name,
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
            'pickList' => [
                'id' => $pickList->id, 'number' => $pickList->number, 'status' => $pickList->status,
                'run' => $pickList->deliveryRun?->label(), 'run_id' => $pickList->delivery_run_id,
                'claimed_by' => $pickList->claimer?->name, 'mine' => $pickList->claimed_by === $request->user()->id,
            ],
            'lines' => $this->service->walkOrder($pickList)->map(fn (PickListLine $l) => $this->lineRow($l))->values(),
            'overrideReasons' => PickPackService::OVERRIDE_REASONS,
        ]);
    }

    public function record(Request $request, PickList $pickList, PickListLine $line): RedirectResponse
    {
        abort_unless($line->pick_list_id === $pickList->id, 404);
        $data = $request->validate([
            'qty_picked_base' => ['required', 'integer', 'min:0'],
            'bin_barcode' => ['nullable', 'string', 'max:64'],
            'product_barcodes' => ['array', 'max:500'],
            'product_barcodes.*' => ['string', 'max:64'],
            'override_reason' => ['nullable', Rule::in(PickPackService::OVERRIDE_REASONS)],
        ]);
        $this->guard(fn () => $this->service->recordLine($line, $request->user(), (int) $data['qty_picked_base'],
            $data['bin_barcode'] ?? null, array_values($data['product_barcodes'] ?? []), $data['override_reason'] ?? null));

        return back();
    }

    public function packPage(PickList $pickList): Response
    {
        $lines = $this->service->walkOrder($pickList);
        $packs = OrderPack::where('pick_list_id', $pickList->id)->with('packer:id,name')->get()->keyBy('order_id');

        return Inertia::render('warehouse/Pack', [
            'pickList' => ['id' => $pickList->id, 'number' => $pickList->number, 'status' => $pickList->status, 'run_id' => $pickList->delivery_run_id],
            'orders' => $lines->groupBy(fn ($l) => $l->orderLine->order_id)->map(function ($group) use ($packs) {
                $order = $group->first()->orderLine->order;
                $pack = $packs[$order->id] ?? null;

                return [
                    'id' => $order->id, 'number' => $order->number, 'customer' => $order->customer?->name,
                    'picking_done' => $group->every(fn ($l) => $l->qty_picked_base !== null),
                    'lines' => $group->map(fn (PickListLine $l) => $this->lineRow($l))->values(),
                    'pack' => $pack ? ['cartons' => $pack->cartons, 'packed_by' => $pack->packer->name, 'packed_at' => $pack->packed_at->toIso8601String()] : null,
                ];
            })->values(),
        ]);
    }

    public function pack(Request $request, PickList $pickList, Order $order): RedirectResponse
    {
        $data = $request->validate([
            'cartons' => ['required', 'integer', 'min:0', 'max:500'],
            'checked' => ['array'],
            'checked.*' => ['integer'],
        ]);
        $this->guard(fn () => $this->service->pack($pickList, $order, $request->user(), (int) $data['cartons'], $data['checked'] ?? []));

        return back()->with('success', "{$order->number} packed.");
    }

    public function slip(PickList $pickList, Order $order, PdfRenderer $pdf): HttpResponse
    {
        $order->load(['customer', 'deliveryRun.area']);
        $lines = $pickList->lines()->whereHas('orderLine', fn ($q) => $q->where('order_id', $order->id))
            ->with(['product', 'orderLine.unit'])->get()->groupBy('order_line_id');
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
            ];
        })->values();

        $body = $pdf->render('pdf.packing-slip', [
            'order' => $order, 'rows' => $rows, 'company' => Setting::all_(),
            'pack' => OrderPack::where('order_id', $order->id)->where('pick_list_id', $pickList->id)->with('packer')->first(),
        ], "Packing slip {$order->number}", ['format' => 'A5']);

        return response($body, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="packing-slip-'.$order->number.'.pdf"']);
    }

    public function manifest(DeliveryRun $run): Response
    {
        $run->load('area');
        $orders = $run->orders()->with(['customer', 'packs'])->orderBy('id')->get();

        return Inertia::render('warehouse/RunManifest', [
            'run' => ['id' => $run->id, 'label' => $run->label(), 'status' => $run->status],
            'orders' => $orders->map(fn (Order $o) => [
                'id' => $o->id, 'number' => $o->number, 'status' => $o->status->value, 'status_label' => $o->status->label(),
                'customer' => $o->customer?->name,
                'address' => trim(($o->delivery_street ?? $o->customer?->delivery_street).' '.($o->delivery_suburb ?? $o->customer?->delivery_suburb).' '.($o->delivery_postcode ?? $o->customer?->delivery_postcode)),
                'cartons' => (int) $o->packs->sum('cartons'), 'packed' => $o->packs->isNotEmpty(),
            ]),
            'openLists' => $run->pickLists()->where('status', PickList::STATUS_OPEN)->get(['id', 'number']),
        ]);
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
            'order' => ['id' => $ol->order_id, 'number' => $ol->order->number, 'customer' => $ol->order->customer?->name],
            'bin' => ['code' => $l->location->code, 'path' => $l->location->path(), 'barcode' => $l->location->barcode],
            'product' => ['sku' => $l->product->sku, 'name' => $l->product->name, 'chilled' => $l->product->is_chilled, 'base_unit' => $l->product->base_unit],
            'pack' => ['name' => $ol->unit->name, 'base_qty' => $per],
            'barcodes' => $l->product->units->flatMap(fn ($u) => $u->barcodes->map(fn ($b) => ['code' => $b->barcode, 'base_qty' => $u->base_qty]))->values(),
            'batch' => $l->batch ? ['lot' => $l->batch->lot_code, 'best_before' => $l->batch->best_before?->toDateString()] : null,
            'to_pick_base' => $l->qty_to_pick_base,
            'to_pick_label' => $this->packs($l->qty_to_pick_base, $per).' '.$ol->unit->name,
            'picked_base' => $l->qty_picked_base,
            'scanned' => (bool) ($l->bin_scanned_at && $l->product_scanned_at),
            'override_reason' => $l->override_reason,
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
