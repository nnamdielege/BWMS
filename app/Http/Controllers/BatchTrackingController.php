<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\Stock\BatchTrackingService;
use App\Services\Stock\Exceptions\StockException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Catalogue -> Batch tracking: turn it on for products that already hold stock, with a preview per bin. */
class BatchTrackingController extends Controller
{
    private const MAX = 300;

    public function __construct(private BatchTrackingService $batches) {}

    /** ?products=1,2,3 is the preview: the import's skipped list, a product page or a range lands here. */
    public function index(Request $request): Response
    {
        $ids = collect(explode(',', (string) $request->query('products')))->filter(fn ($id) => ctype_digit($id))->unique()->take(self::MAX);
        $products = Product::whereIn('id', $ids)->with('range:id,name')->orderBy('sku')->get();

        return Inertia::render('products/BatchTracking', [
            'rows' => $products->map(fn (Product $p) => [
                'id' => $p->id, 'sku' => $p->sku, 'name' => $p->name, 'range' => $p->range?->name, 'base_unit' => $p->base_unit,
                'bins' => $this->batches->bins($p), 'blocker' => $this->batches->blocker($p),
            ]),
            // Selectable ranges, as the import preview shows them: name and count, with who is in each.
            'ranges' => Product::where('is_batch_tracked', false)->where('is_stock_tracked', true)->where('is_active', true)->whereNotNull('range_id')
                ->with('range:id,name')->get(['id', 'range_id'])->groupBy('range.name')->sortKeys()
                ->map(fn ($group, $name) => ['name' => $name, 'count' => $group->count(), 'ids' => $group->pluck('id')])->values(),
            'today' => now(config('bwms.business_timezone'))->toDateString(),
        ]);
    }

    /** One transaction per product, so a product that can't be turned on doesn't hold the others back. */
    public function apply(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'products' => ['required', 'array', 'min:1', 'max:'.self::MAX],
            'products.*.id' => ['required', 'integer', 'distinct'],
            'products.*.bins' => ['present', 'array'],
            'products.*.bins.*.location_id' => ['required', 'integer'],
            'products.*.bins.*.best_before' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $done = 0;
        $skipped = [];
        foreach ($data['products'] as $row) {
            try {
                $this->batches->enable(Product::findOrFail($row['id']), collect($row['bins'])->mapWithKeys(fn ($b) => [$b['location_id'] => $b['best_before'] ?? null])->all(), $request->user());
                $done++;
            } catch (StockException $e) {
                $skipped[] = $e->getMessage();
            }
        }

        $message = "Batch tracking turned on for {$done} product(s).";
        if ($skipped !== []) {
            return back()->with('error', $message.' Not changed: '.implode(' ', $skipped));
        }

        return redirect()->route('batch-tracking.index')->with('success', $message);
    }
}
