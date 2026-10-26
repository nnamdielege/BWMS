<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProductRequest;
use App\Models\Barcode;
use App\Models\Category;
use App\Models\Product;
use App\Models\Range;
use App\Services\Catalogue\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    public function __construct(private ProductService $products) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:active,inactive,all'],
            'range_id' => ['nullable', 'integer'],
        ]);

        $products = Product::query()
            ->with(['category:id,name', 'range:id,name', 'units:id,product_id,name,base_qty'])
            ->search($filters['q'] ?? null)
            ->when($filters['range_id'] ?? null, fn ($q, $r) => $q->where('range_id', $r))
            ->when(($filters['status'] ?? 'active') !== 'all', fn ($q) => $q->where('is_active', ($filters['status'] ?? 'active') === 'active'))
            ->orderBy('name')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('products/Index', [
            'products' => $products,
            'filters' => $filters,
            'ranges' => Range::orderBy('name')->get(['id', 'name']),
            'canManage' => $request->user()->can('create', Product::class),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('products/Form', [
            'product' => null,
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'ranges' => Range::orderBy('name')->get(['id', 'name']),
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
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'ranges' => Range::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(ProductRequest $request, Product $product): RedirectResponse
    {
        $this->products->save($product, $request->validated());

        return back()->with('success', "Product {$product->sku} saved.");
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
