<?php

namespace App\Http\Controllers;

use App\Authorization\Permissions;
use App\Models\Barcode;
use App\Models\ProductUnit;
use App\Services\Catalogue\BarcodeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Catalogue → Barcode lookup: which pack a barcode is on; and correcting barcodes (change,
 * remove, move to another pack), from here or from the product page. Changes: managers, admins.
 */
class BarcodeController extends Controller
{
    public function __construct(private BarcodeService $barcodes) {}

    public function index(Request $request): Response
    {
        $code = trim((string) $request->validate(['code' => ['nullable', 'string', 'max:100']])['code'] ?? '');
        $match = $code === '' ? null : Barcode::where('barcode', $code)->with('unit.product:id,sku,name,base_unit', 'unit.barcodes:id,product_unit_id')->first();

        return Inertia::render('products/BarcodeLookup', [
            'code' => $code === '' ? null : $code,
            'match' => $match ? self::describe($match) : null,
            'can' => ['manage' => $request->user()->can(Permissions::PRODUCTS_MANAGE)],
        ]);
    }

    public function update(Request $request, Barcode $barcode): RedirectResponse
    {
        $data = $request->validate(['barcode' => ['required', 'string', 'max:64']]);
        $old = $barcode->barcode;
        $this->barcodes->update($barcode, $data['barcode']);

        return back()->with('success', $old === $barcode->barcode ? 'No change.' : "Barcode {$old} changed to {$barcode->barcode}.");
    }

    public function destroy(Barcode $barcode): RedirectResponse
    {
        $warning = $this->barcodes->remove($barcode);

        return back()->with('success', "Barcode {$barcode->barcode} removed.")->with('warning', $warning);
    }

    public function move(Request $request, Barcode $barcode): RedirectResponse
    {
        $data = $request->validate(['product_unit_id' => ['required', 'integer', 'exists:product_units,id']]);
        $to = ProductUnit::with('product:id,sku')->findOrFail($data['product_unit_id']);
        $warning = $this->barcodes->move($barcode, $to);

        return back()->with('success', "Barcode {$barcode->barcode} moved to {$to->product->sku} ({$to->name}).")->with('warning', $warning);
    }

    /** What the screens need to show a barcode and warn before taking it off its pack. */
    public static function describe(Barcode $b): array
    {
        $unit = $b->unit;

        return [
            'id' => $b->id,
            'barcode' => $b->barcode,
            'type' => $b->type,
            'product' => $unit->product->only(['id', 'sku', 'name']),
            'unit' => ['id' => $unit->id, 'name' => $unit->name, 'base_qty' => (int) $unit->base_qty],
            'last_on_pack' => $unit->barcodes->count() <= 1,
            'open_pick_lists' => BarcodeService::openPickLists($unit->product_id),
        ];
    }
}
