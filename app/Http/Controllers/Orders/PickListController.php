<?php

namespace App\Http\Controllers\Orders;

use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\OrderLine;
use App\Models\PickList;
use App\Models\PickListLine;
use App\Services\Orders\PickListService;
use App\Services\Stock\Exceptions\StockException;
use App\Support\BusinessDate;
use App\Support\PackQty;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PickListController extends Controller
{
    public function __construct(private PickListService $picks) {}

    public function index(Request $request): Response
    {
        $view = $request->validate(['view' => ['nullable', 'in:open,history']])['view'] ?? 'open';
        $lists = PickList::with(['site:id,name', 'creator:id,name', 'deliveryRun.area:id,name'])->withCount('lines')
            ->addSelect(['delivery_date' => OrderLine::query()->join('orders', 'orders.id', '=', 'order_lines.order_id')
                ->join('pick_list_lines', 'pick_list_lines.order_line_id', '=', 'order_lines.id')
                ->whereColumn('pick_list_lines.pick_list_id', 'pick_lists.id')->selectRaw('MIN(orders.delivery_date)')]);
        $row = fn (PickList $p) => [
            'id' => $p->id, 'number' => $p->number, 'site' => $p->site->name, 'status' => $p->status, 'stage' => $p->stage(),
            'delivery_date' => $p->delivery_date, 'run' => $p->deliveryRun?->area?->name,
            'lines' => $p->lines_count, 'created_by' => $p->creator->name, 'created_at' => $p->created_at->toIso8601String(),
        ];

        return Inertia::render('pick-lists/Index', [
            'today' => BusinessDate::today(),
            'view' => $view,
            'open' => $view === 'open'
                ? $this->withOversold((clone $lists)->where('status', PickList::STATUS_OPEN)->orderByRaw('delivery_date IS NULL, delivery_date')->orderBy('delivery_run_id')->get()->map($row)->values())
                : [],
            'history' => $view === 'history'
                ? $lists->where('status', '!=', PickList::STATUS_OPEN)->latest('id')->paginate(30)->withQueryString()->through($row)
                : null,
        ]);
    }

    /** Each open list's orders' oversold SKUs, as on the order flag ("Oversold: MLKALM, MLKOAT"). */
    private function withOversold(Collection $rows): Collection
    {
        $skus = DB::table('pick_list_lines')
            ->join('order_lines as listed', 'listed.id', '=', 'pick_list_lines.order_line_id')
            ->join('orders', 'orders.id', '=', 'listed.order_id')
            ->join('order_lines as short', 'short.order_id', '=', 'orders.id')
            ->join('products', 'products.id', '=', 'short.product_id')
            ->whereIn('pick_list_lines.pick_list_id', $rows->pluck('id'))
            ->where('short.oversold_base', '>', 0)
            ->whereIn('orders.status', ['open', 'picking', 'partially_dispatched'])
            ->distinct()->orderBy('products.sku')
            ->get(['pick_list_lines.pick_list_id', 'products.sku'])
            ->groupBy('pick_list_id');

        return $rows->map(fn (array $r) => $r + ['oversold_skus' => ($skus[$r['id']] ?? collect())->pluck('sku')->values()->all()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'site_id' => ['required', 'integer', 'exists:locations,id'],
            'order_ids' => ['required', 'array', 'min:1', 'max:100'],
            'order_ids.*' => ['integer'],
        ]);

        try {
            ['pickList' => $pickList, 'shortfalls' => $short] = $this->picks->create($request->user(), Location::findOrFail($data['site_id']), $data['order_ids']);
        } catch (StockException $e) {
            throw ValidationException::withMessages(['pick' => $e->getMessage()]);
        }

        $message = "Pick list {$pickList->number} created.";
        if ($short !== []) {
            $message .= ' Short: '.collect($short)->map(fn ($s) => "{$s['sku']} ×{$s['short']} ({$s['order']})")->implode(', ').'.';
        }

        return redirect()->route('pick-lists.show', $pickList)->with($short ? 'error' : 'success', $message);
    }

    public function show(Request $request, PickList $pickList): Response
    {
        $pickList->load(['site', 'lines.product:id,sku,name,base_unit', 'lines.location.parent', 'lines.batch', 'lines.orderLine.order.customer:id,name', 'lines.orderLine.unit:id,name,base_qty', 'lines.orderLine.substituteFor.product:id,sku,name', 'deliveryRun.area']);

        return Inertia::render('pick-lists/Show', [
            'pickList' => [
                'id' => $pickList->id, 'number' => $pickList->number, 'status' => $pickList->status, 'stage' => $pickList->stage(), 'site' => $pickList->site->name,
                'dispatched_at' => $pickList->dispatched_at?->toIso8601String(),
                'run' => $pickList->deliveryRun?->label(),
                // Walk order: by bin, then earliest best-before.
                'lines' => $pickList->lines->sortBy([fn ($l) => $l->location?->path() ?? "\u{FFFF}", fn ($l) => $l->batch?->best_before?->timestamp ?? PHP_INT_MAX])->values()
                    ->map(fn (PickListLine $l) => [
                        'id' => $l->id,
                        'location' => $l->location?->path() ?? 'No stock — short',
                        'no_stock' => $l->no_stock,
                        'product' => $l->product,
                        'batch' => $l->batch?->label(),
                        'order' => $l->orderLine->order->number,
                        'channel' => $l->orderLine->order->channel->value,
                        'customer' => $l->orderLine->order->customer?->name,
                        'sub_label' => $l->orderLine->subLabel(),
                        'pack' => PackQty::forInput($l->orderLine->base_qty_per_unit, $l->orderLine->unit->name, $l->product->base_unit),
                        'qty_to_pick_base' => $l->qty_to_pick_base,
                        'qty_picked_base' => $l->qty_picked_base,
                        'to_pick_label' => PackQty::label($l->qty_to_pick_base, $l->orderLine->base_qty_per_unit, $l->orderLine->unit->name, $l->product->base_unit),
                    ]),
            ],
            'can' => ['pick' => $request->user()->can('picking.perform') && $pickList->isOpen()],
        ]);
    }

    public function record(Request $request, PickList $pickList): RedirectResponse
    {
        // Typed in the order line's pack plus loose units, saved as base units.
        $data = $request->validate(['picked' => ['required', 'array'], 'picked.*' => ['array'], ...PackQty::rules('picked.*.')]);
        $per = $pickList->lines()->whereIn('id', array_keys($data['picked']))->with('orderLine:id,base_qty_per_unit')->get()
            ->mapWithKeys(fn (PickListLine $l) => [$l->id => $l->orderLine->base_qty_per_unit]);
        $picked = collect($data['picked'])
            ->filter(fn ($entry, $id) => isset($per[$id]) && ! PackQty::isBlank($entry))
            ->map(fn ($entry, $id) => PackQty::toBase($entry, $per[$id]))
            ->all();
        $this->guard(fn () => $this->picks->recordPicked($pickList, $picked));

        return back();
    }

    public function dispatch(Request $request, PickList $pickList): RedirectResponse
    {
        $this->guard(fn () => $this->picks->dispatch($pickList, $request->user()));

        return back()->with('success', "{$pickList->number} dispatched; stock deducted.");
    }

    public function cancel(PickList $pickList): RedirectResponse
    {
        $this->guard(fn () => $this->picks->cancel($pickList));

        return back()->with('success', "{$pickList->number} cancelled; orders are ready to pick again.");
    }

    private function guard(callable $fn): void
    {
        try {
            $fn();
        } catch (StockException $e) {
            throw ValidationException::withMessages(['pick' => $e->getMessage()]);
        }
    }
}
