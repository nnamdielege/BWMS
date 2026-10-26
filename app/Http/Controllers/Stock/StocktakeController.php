<?php

namespace App\Http\Controllers\Stock;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\Location;
use App\Models\Product;
use App\Models\Stocktake;
use App\Models\StocktakeLine;
use App\Services\Stock\Exceptions\StockException;
use App\Services\Stock\StocktakeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class StocktakeController extends Controller
{
    public function __construct(private StocktakeService $stocktakes) {}

    public function index(Request $request): Response
    {
        return Inertia::render('stocktakes/Index', [
            'stocktakes' => Stocktake::with(['scope', 'creator:id,name'])->withCount('lines')->latest('id')->paginate(30)
                ->through(fn (Stocktake $s) => [
                    'id' => $s->id,
                    'number' => $s->number,
                    'scope' => $s->scope->path(),
                    'status' => $s->status,
                    'lines' => $s->lines_count,
                    'created_by' => $s->creator->name,
                    'snapshot_at' => $s->snapshot_at->toIso8601String(),
                ]),
            'scopes' => Location::where('is_active', true)->with('parent.parent')->orderBy('type')->orderBy('code')->get()
                ->map(fn (Location $l) => ['id' => $l->id, 'label' => $l->path().' ('.$l->type.')']),
            'canManage' => $request->user()->can('stocktakes.manage'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'scope_location_id' => ['required', 'integer', 'exists:locations,id'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $stocktake = $this->stocktakes->create($request->user(), Location::findOrFail($data['scope_location_id']), $data['note'] ?? null);

        return redirect()->route('stocktakes.show', $stocktake)->with('success', "Stocktake {$stocktake->number} started.");
    }

    public function show(Request $request, Stocktake $stocktake): Response
    {
        $stocktake->load(['scope', 'lines.product:id,sku,name,base_unit', 'lines.location.parent', 'lines.batch']);

        return Inertia::render('stocktakes/Show', [
            'stocktake' => [
                'id' => $stocktake->id,
                'number' => $stocktake->number,
                'status' => $stocktake->status,
                'scope' => $stocktake->scope->path(),
                'note' => $stocktake->note,
                'snapshot_at' => $stocktake->snapshot_at->toIso8601String(),
                'posted_at' => $stocktake->posted_at?->toIso8601String(),
            ],
            'lines' => $stocktake->lines->sortBy([fn ($l) => $l->location->code, fn ($l) => $l->product->name])->values()
                ->map(fn (StocktakeLine $l) => [
                    'id' => $l->id,
                    'product' => $l->product->only(['id', 'sku', 'name', 'base_unit']),
                    'location' => $l->location->path(),
                    'batch' => $l->batch?->label(),
                    'snapshot_qty' => $l->snapshot_qty,
                    'counted_qty' => $l->counted_qty,
                    'system_qty_at_count' => $l->system_qty_at_count,
                    'variance' => $l->posted_variance ?? $l->variance(),
                ]),
            'bins' => Location::bins()->where('is_active', true)->with('parent.parent')->orderBy('code')->get()
                ->map(fn (Location $l) => ['id' => $l->id, 'label' => $l->path()]),
            'can' => [
                'count' => $request->user()->can('stocktakes.count'),
                'manage' => $request->user()->can('stocktakes.manage'),
            ],
        ]);
    }

    public function count(Request $request, Stocktake $stocktake, StocktakeLine $line): RedirectResponse
    {
        abort_unless($line->stocktake_id === $stocktake->id, 404);
        $data = $request->validate(['counted_qty' => ['required', 'integer', 'min:0', 'max:10000000']]);

        $this->guard(fn () => $this->stocktakes->recordCount($line, (int) $data['counted_qty'], $request->user()));

        return back();
    }

    public function addLine(Request $request, Stocktake $stocktake): RedirectResponse
    {
        $data = $request->validate([
            'sku' => ['required', 'string', 'exists:products,sku'],
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'lot_code' => ['nullable', 'string', 'max:64'],
            'best_before' => ['nullable', 'date'],
        ]);
        $product = Product::where('sku', strtoupper($data['sku']))->firstOrFail();
        $batch = ! empty($data['lot_code']) ? Batch::findOrCreateFor($product, $data['lot_code'], $data['best_before'] ?? null) : null;

        $this->guard(fn () => $this->stocktakes->addLine($stocktake, $product, Location::findOrFail($data['location_id']), $batch));

        return back()->with('success', "Line added for {$product->sku}.");
    }

    public function post(Request $request, Stocktake $stocktake): RedirectResponse
    {
        $data = $request->validate(['zero_uncounted' => ['boolean']]);

        $this->guard(fn () => $this->stocktakes->post($stocktake, $request->user(), (bool) ($data['zero_uncounted'] ?? false)));

        return back()->with('success', "Stocktake {$stocktake->number} posted.");
    }

    public function cancel(Stocktake $stocktake): RedirectResponse
    {
        $this->guard(fn () => $this->stocktakes->cancel($stocktake));

        return back()->with('success', "Stocktake {$stocktake->number} cancelled.");
    }

    private function guard(callable $fn): void
    {
        try {
            $fn();
        } catch (StockException $e) {
            throw ValidationException::withMessages(['stocktake' => $e->getMessage()]);
        }
    }
}
