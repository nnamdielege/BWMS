<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProductRequest;
use App\Models\Barcode;
use App\Models\Category;
use App\Models\Product;
use App\Models\Range;
use App\Models\StockBalance;
use App\Models\Supplier;
use App\Services\Catalogue\BarcodeService;
use App\Services\Catalogue\ProductService;
use App\Services\Catalogue\SellPriceCheck;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    public function __construct(private ProductService $products) {}

    public function index(Request $request, SellPriceCheck $priceCheck): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:active,inactive,all'],
            'range_id' => ['nullable', 'integer'],
            'gst' => ['nullable', 'in:free,taxable'],
            'check' => ['nullable', 'in:below_cost'],
        ]);
        // Sell price below cost per pack shows costs, so only to those who may see them (as Product costs).
        $belowCost = $request->user()->can('reports.view') ? $priceCheck->belowCost() : null;
        $onlyBelowCost = ($filters['check'] ?? null) === 'below_cost' && $belowCost !== null;

        $products = Product::query()
            ->with(['category:id,name', 'range:id,name', 'units:id,product_id,name,base_qty'])
            ->when($onlyBelowCost, fn ($q) => $q->whereIn('id', array_column($belowCost, 'id')))
            ->search($filters['q'] ?? null)
            ->when($filters['range_id'] ?? null, fn ($q, $r) => $q->where('range_id', $r))
            ->when($filters['gst'] ?? null, fn ($q, $g) => $q->where('gst_free', $g === 'free'))
            ->when(($filters['status'] ?? 'active') !== 'all', fn ($q) => $q->where('is_active', ($filters['status'] ?? 'active') === 'active'))
            ->orderBy('name')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('products/Index', [
            'products' => $products,
            'filters' => $filters,
            'ranges' => Range::orderBy('name')->get(['id', 'name']),
            'canManage' => $request->user()->can('create', Product::class),
            // null: not allowed to see costs. Details only while the list is filtered to them.
            'priceCheck' => $belowCost === null ? null : ['count' => count($belowCost), 'rows' => $onlyBelowCost ? $belowCost : []],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('products/Form', [
            'product' => null,
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'ranges' => Range::orderBy('name')->get(['id', 'name']),
            'suppliers' => Supplier::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(ProductRequest $request): RedirectResponse
    {
        $product = $this->products->save(new Product, $request->validated());

        return redirect()->route('products.edit', $product)->with('success', "Product {$product->sku} created.");
    }

    public function edit(Product $product): Response
    {
        return Inertia::render('products/Form', [
            'product' => $product->load('units.barcodes'),
            'holdsStock' => StockBalance::where('product_id', $product->id)->where('on_hand', '!=', 0)->exists(),
            // Taking the last barcode off a pack warns when pickers still need this product.
            'openPickLists' => BarcodeService::openPickLists($product->id),
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'ranges' => Range::orderBy('name')->get(['id', 'name']),
            'suppliers' => Supplier::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(ProductRequest $request, Product $product): RedirectResponse
    {
        $this->products->save($product, $request->validated());

        return back()->with('success', "Product {$product->sku} saved.");
    }

    /** Typeahead for order/PO line entry: active products with their pack sizes. */
    public function search(Request $request): JsonResponse
    {
        $q = (string) $request->validate(['q' => ['required', 'string', 'min:1', 'max:100']])['q'];

        return response()->json(
            Product::search($q)->where('is_active', true)
                ->with('units:id,product_id,name,base_qty,purchasable,sellable,default_purchase,default_sale')
                ->orderBy('name')->limit(20)
                ->get(['id', 'sku', 'name', 'base_unit', 'avg_cost', 'sell_price', 'is_batch_tracked', 'gst_free']),
        );
    }

    /** Scanner lookup: resolve a barcode to its product and pack size. */
    public function lookupBarcode(string $barcode): JsonResponse
    {
        $match = Barcode::where('barcode', $barcode)->with('unit.product:id,sku,name,base_unit,is_batch_tracked')->first();
        abort_unless($match, 404, 'Unknown barcode.');

        return response()->json([
            'product' => $match->unit->product,
            'unit' => $match->unit->only(['id', 'name', 'base_qty']),
        ]);
    }
}
