<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductUnit;
use App\Services\Catalogue\CostBackfillService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * D-11 cost back-fill: zero-cost report, inline edit, CSV import with preview.
 */
class ProductCostController extends Controller
{
    public function __construct(private CostBackfillService $costs) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'zero' => ['nullable', 'boolean']]);
        $zeroOnly = $filters['zero'] ?? true;

        $products = Product::query()
            ->with('units:id,product_id,name,base_qty')
            ->search($filters['q'] ?? null)
            ->when($zeroOnly, fn ($q) => $q->where('avg_cost', 0))
            ->orderBy('name')
            ->paginate(100, ['id', 'sku', 'name', 'base_unit', 'avg_cost', 'is_active'])
            ->withQueryString();

        return Inertia::render('products/Costs', [
            'products' => $products,
            'filters' => ['q' => $filters['q'] ?? '', 'zero' => $zeroOnly],
            'zeroCount' => Product::where('avg_cost', 0)->count(),
            'preview' => $request->session()->get('cost_preview'),
            'canEdit' => $request->user()->can('products.manage'),
        ]);
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $data = $request->validate([
            'cost' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'product_unit_id' => ['nullable', 'integer'],
        ]);

        $unit = isset($data['product_unit_id'])
            ? $product->units()->whereKey($data['product_unit_id'])->firstOrFail()
            : $product->units()->where('base_qty', 1)->firstOrFail();

        $this->costs->setCost($product, $this->costs->perBase((string) $data['cost'], $unit), $request->user(), 'manual');

        return back()->with('success', "Cost for {$product->sku} saved.");
    }

    /** Step 1: parse + validate; nothing changes. The preview is kept server-side under a token. */
    public function preview(Request $request): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:2048']]);

        $result = $this->costs->preview($request->file('file')->getRealPath());
        $token = (string) Str::uuid();
        Cache::put("cost-import:{$request->user()->id}:{$token}", $result['rows'], now()->addMinutes(30));

        return back()->with('cost_preview', [
            'token' => $token,
            'rows' => array_slice($result['rows'], 0, 500),
            'valid' => $result['valid'],
            'errors' => $result['errors'],
            'total' => count($result['rows']),
        ]);
    }

    /** Step 2: apply a preview once. The token is consumed, so a double submit changes nothing. */
    public function apply(Request $request): RedirectResponse
    {
        $data = $request->validate(['token' => ['required', 'uuid']]);
        $rows = Cache::pull("cost-import:{$request->user()->id}:{$data['token']}");

        if ($rows === null) {
            return back()->with('error', 'That preview has expired or was already applied. Upload the file again.');
        }

        $changed = $this->costs->apply($rows, $request->user(), 'csv_import');

        return back()->with('success', "Costs updated for {$changed} product(s).");
    }

    /** Zero-cost report as CSV, usable as the import template. */
    public function export(): StreamedResponse
    {
        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['sku', 'cost', 'pack', 'name', 'base_unit', 'available_packs']);
            Product::with('units')->where('avg_cost', 0)->orderBy('sku')->chunk(500, function ($products) use ($out) {
                foreach ($products as $p) {
                    $packs = $p->units->map(fn (ProductUnit $u) => $u->base_qty > 1 ? "{$u->name} ({$u->base_qty})" : $u->name)->implode('; ');
                    fputcsv($out, [$p->sku, '', '', $p->name, $p->base_unit, $packs]);
                }
            });
            fclose($out);
        }, 'products-zero-cost-'.now(config('bwms.business_timezone'))->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }
}
