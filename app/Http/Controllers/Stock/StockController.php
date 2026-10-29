<?php

namespace App\Http\Controllers\Stock;

use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\Product;
use App\Models\ReasonCode;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Services\Orders\AvailabilityService;
use App\Services\Stock\StockQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StockController extends Controller
{
    public function __construct(private StockQuery $stock, private AvailabilityService $availability) {}

    /** Stock on hand by product. */
    public function index(Request $request): Response
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100']]);

        $products = Product::query()
            ->search($filters['q'] ?? null)
            ->where('is_active', true)
            ->orderBy('name')
            ->paginate(50, ['id', 'sku', 'name', 'base_unit', 'is_batch_tracked', 'reorder_point'])
            ->withQueryString();

        $ids = $products->getCollection()->pluck('id')->all();
        $onHand = StockBalance::whereIn('product_id', $ids)->groupBy('product_id')
            ->selectRaw('product_id, SUM(on_hand) as qty')->pluck('qty', 'product_id');
        $sellable = $this->stock->sellableOnHandMany($ids);
        $available = $this->availability->availableMany($ids);

        $products->getCollection()->transform(fn (Product $p) => [
            ...$p->only(['id', 'sku', 'name', 'base_unit', 'is_batch_tracked', 'reorder_point']),
            'on_hand' => (int) ($onHand[$p->id] ?? 0),
            'sellable' => $sellable[$p->id] ?? 0,
            'reserved' => ($sellable[$p->id] ?? 0) - $available[$p->id],
            'available' => $available[$p->id],
        ]);

        return Inertia::render('stock/Index', ['products' => $products, 'filters' => $filters]);
    }

    /** One product: balances by bin/batch, recent movements, adjust/transfer forms. */
    public function show(Request $request, Product $product): Response
    {
        $product->load('units:id,product_id,name,base_qty');

        return Inertia::render('stock/Product', [
            'product' => $product->only(['id', 'sku', 'name', 'base_unit', 'is_batch_tracked']) + ['units' => $product->units],
            'onHand' => $this->stock->onHand($product),
            'sellable' => $this->stock->sellableOnHand($product),
            'reserved' => $this->availability->reserved($product),
            'available' => $this->availability->available($product),
            'balances' => $this->stock->balancesFor($product)->map(fn (StockBalance $b) => [
                'location_id' => $b->location_id,
                'location' => $b->location->path(),
                'location_flags' => ['sellable' => $b->location->is_sellable, 'quarantine' => $b->location->is_quarantine],
                'batch_id' => $b->batch_id,
                'batch' => $b->batch?->label(),
                'best_before' => $b->batch?->best_before?->toDateString(),
                'expired' => $b->batch?->isExpired() ?? false,
                'on_hand' => $b->on_hand,
            ]),
            'movements' => StockMovement::where('product_id', $product->id)
                ->with(['location.parent', 'batch', 'reasonCode', 'creator:id,name'])
                ->latest('id')->limit(25)->get()->map(fn (StockMovement $m) => self::movementRow($m)),
            'bins' => Location::bins()->where('is_active', true)->with('parent.parent')->orderBy('code')->get()
                ->map(fn (Location $l) => ['id' => $l->id, 'label' => $l->path()]),
            'batches' => $product->is_batch_tracked
                ? $product->batches()->orderBy('best_before')->get()->map(fn ($b) => ['id' => $b->id, 'label' => $b->label()])
                : [],
            'reasons' => ReasonCode::where('is_active', true)->orderBy('name')->get(['id', 'code', 'name', 'direction', 'requires_note', 'movement_type']),
            'can' => [
                'adjust' => $request->user()->can('stock.adjust'),
                'transfer' => $request->user()->can('stock.transfer'),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    public static function movementRow(StockMovement $m): array
    {
        return [
            'id' => $m->id,
            'occurred_at' => $m->occurred_at->toIso8601String(),
            'type' => $m->type->value,
            'type_label' => $m->type->label(),
            'qty' => $m->qty,
            'product' => $m->relationLoaded('product') ? $m->product?->only(['id', 'sku', 'name']) : null,
            'location' => $m->location->path(),
            'batch' => $m->batch?->label(),
            'reason' => $m->reasonCode?->name,
            'note' => $m->note,
            'user' => $m->creator?->name,
            'source' => $m->source_type ? $m->source_type.($m->source_id ? " #{$m->source_id}" : '') : null,
        ];
    }
}
