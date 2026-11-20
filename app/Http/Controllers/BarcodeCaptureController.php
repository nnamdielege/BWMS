<?php

namespace App\Http\Controllers;

use App\Authorization\Permissions;
use App\Models\Barcode;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Services\Catalogue\BarcodeCaptureService;
use App\Services\Catalogue\BarcodeService;
use App\Support\WarehouseNav;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Catalogue → Capture barcodes, on a handheld: find a product, scan each pack, saved as you go. */
class BarcodeCaptureController extends Controller
{
    public function __construct(private BarcodeCaptureService $capture, private BarcodeService $barcodes) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate(['product' => ['nullable', 'integer'], 'q' => ['nullable', 'string', 'max:100']]);

        $found = filled($filters['q'] ?? null) ? $this->capture->find($filters['q']) : collect();
        $product = isset($filters['product']) ? Product::find($filters['product']) : ($found->count() === 1 ? $found->first() : null);

        return Inertia::render('products/CaptureBarcodes', [
            'nav' => WarehouseNav::for($request, 'Capture barcodes', route('dashboard')),
            'progress' => $this->capture->progress(),
            'missing' => $this->capture->missing(),
            'product' => $product ? $this->productRow($product) : null,
            'results' => $product ? [] : $found->map(fn (Product $p) => $p->only(['id', 'sku', 'name']))->values(),
            'query' => $product ? null : ($filters['q'] ?? null),
            'conflict' => session('barcode_conflict'),
            'canMove' => $request->user()->can(Permissions::PRODUCTS_MANAGE),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['product_unit_id' => ['required', 'integer', 'exists:product_units,id'], 'barcode' => ['required', 'string', 'max:100']]);
        $unit = ProductUnit::findOrFail($data['product_unit_id']);
        try {
            $this->capture->add($unit, $data['barcode']);
        } catch (ValidationException $e) {
            // On another pack: managers and admins may move it here (the screen asks first).
            $holder = BarcodeCaptureService::holder($data['barcode'], $unit);
            if ($holder && $request->user()->can(Permissions::PRODUCTS_MANAGE)) {
                session()->flash('barcode_conflict', $holder + ['to_unit_id' => $unit->id]);
            }
            throw $e;
        }

        return redirect()->route('barcode-capture.index', ['product' => $unit->product_id]);
    }

    /** "Move it here": off the pack it's on, onto this one, in one step. */
    public function move(Request $request): RedirectResponse
    {
        $data = $request->validate(['barcode_id' => ['required', 'integer', 'exists:barcodes,id'], 'product_unit_id' => ['required', 'integer', 'exists:product_units,id']]);
        $unit = ProductUnit::findOrFail($data['product_unit_id']);
        $barcode = Barcode::with('unit.product')->findOrFail($data['barcode_id']);
        $from = "{$barcode->unit->product->sku} ({$barcode->unit->name})";
        $warning = $this->barcodes->move($barcode, $unit);

        return redirect()->route('barcode-capture.index', ['product' => $unit->product_id])
            ->with('success', "{$barcode->barcode} moved here from {$from}.")
            ->with('warning', $warning);
    }

    private function productRow(Product $product): array
    {
        return [
            'id' => $product->id, 'sku' => $product->sku, 'name' => $product->name,
            'units' => $product->units()->with('barcodes:id,product_unit_id,barcode')->get()->map(fn (ProductUnit $u) => [
                'id' => $u->id, 'name' => $u->name, 'base_qty' => $u->base_qty, 'default_sale' => $u->default_sale, 'barcodes' => $u->barcodes->pluck('barcode'),
            ]),
        ];
    }
}
