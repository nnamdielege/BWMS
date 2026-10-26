<?php

namespace App\Http\Controllers\Orders;

use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\PickList;
use App\Models\PickListLine;
use App\Services\Orders\PickListService;
use App\Services\Stock\Exceptions\StockException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PickListController extends Controller
{
    public function __construct(private PickListService $picks) {}

    public function index(): Response
    {
        return Inertia::render('pick-lists/Index', [
            'pickLists' => PickList::with(['site:id,name', 'creator:id,name'])->withCount('lines')
                ->latest('id')->paginate(30)
                ->through(fn (PickList $p) => [
                    'id' => $p->id, 'number' => $p->number, 'site' => $p->site->name, 'status' => $p->status,
                    'lines' => $p->lines_count, 'created_by' => $p->creator->name, 'created_at' => $p->created_at->toIso8601String(),
                ]),
        ]);
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
        $pickList->load(['site', 'lines.product:id,sku,name,base_unit', 'lines.location.parent', 'lines.batch', 'lines.orderLine.order.customer:id,name', 'lines.orderLine.unit:id,name,base_qty']);

        return Inertia::render('pick-lists/Show', [
            'pickList' => [
                'id' => $pickList->id, 'number' => $pickList->number, 'status' => $pickList->status, 'site' => $pickList->site->name,
                'dispatched_at' => $pickList->dispatched_at?->toIso8601String(),
                // Walk order: by bin, then earliest best-before.
                'lines' => $pickList->lines->sortBy([fn ($l) => $l->location->path(), fn ($l) => $l->batch?->best_before?->timestamp ?? PHP_INT_MAX])->values()
                    ->map(fn (PickListLine $l) => [
                        'id' => $l->id,
                        'location' => $l->location->path(),
                        'product' => $l->product,
                        'batch' => $l->batch?->label(),
                        'order' => $l->orderLine->order->number,
                        'customer' => $l->orderLine->order->customer?->name,
                        'pack' => $l->orderLine->unit->name,
                        'pack_qty' => $l->orderLine->base_qty_per_unit,
                        'qty_to_pick_base' => $l->qty_to_pick_base,
                        'qty_picked_base' => $l->qty_picked_base,
                    ]),
            ],
            'can' => ['pick' => $request->user()->can('picking.perform') && $pickList->isOpen()],
        ]);
    }

    public function record(Request $request, PickList $pickList): RedirectResponse
    {
        $data = $request->validate(['picked' => ['required', 'array'], 'picked.*' => ['integer', 'min:0']]);
        $this->guard(fn () => $this->picks->recordPicked($pickList, $data['picked']));

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
