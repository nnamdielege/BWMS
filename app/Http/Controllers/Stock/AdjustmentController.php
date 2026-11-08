<?php

namespace App\Http\Controllers\Stock;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\Location;
use App\Models\Product;
use App\Models\ReasonCode;
use App\Services\Stock\AdjustmentService;
use App\Services\Stock\Exceptions\StockException;
use App\Support\PackQty;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdjustmentController extends Controller
{
    public function __construct(private AdjustmentService $adjustments) {}

    public function store(Request $request, Product $product): RedirectResponse
    {
        $data = $request->validate([
            'client_token' => ['required', 'uuid'],
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'direction' => ['required', Rule::in(['in', 'out'])],
            'product_unit_id' => ['nullable', 'integer', Rule::exists('product_units', 'id')->where('product_id', $product->id)],
            ...PackQty::rules(),
            'reason_code_id' => ['required', 'integer', 'exists:reason_codes,id'],
            'note' => ['nullable', 'string', 'max:1000'],
            'batch_id' => ['nullable', 'integer', 'exists:batches,id'],
            'new_batch.lot_code' => ['nullable', 'required_with:new_batch.best_before', 'string', 'max:64'],
            'new_batch.best_before' => ['nullable', 'date'],
        ]);

        // Typed in a pack plus loose units, saved as base units.
        $per = $product->units()->whereKey($data['product_unit_id'] ?? 0)->value('base_qty') ?? 1;
        $qty = PackQty::toBase($data, $per);
        if ($qty === 0) {
            throw ValidationException::withMessages(['qty' => 'Enter a quantity.']);
        }
        $qty = $data['direction'] === 'out' ? -$qty : $qty;

        $batch = match (true) {
            ! empty($data['batch_id']) => Batch::findOrFail($data['batch_id']),
            ! empty($data['new_batch']['lot_code']) => Batch::findOrCreateFor($product, $data['new_batch']['lot_code'], $data['new_batch']['best_before'] ?? null),
            default => null,
        };

        try {
            $this->adjustments->adjust(
                $request->user(), $product, Location::findOrFail($data['location_id']), $qty,
                ReasonCode::findOrFail($data['reason_code_id']), $data['note'] ?? null, $data['client_token'], $batch,
            );
        } catch (StockException $e) {
            throw ValidationException::withMessages(['qty' => $e->getMessage()]);
        }

        return back()->with('success', 'Stock adjusted.');
    }
}
