<?php

namespace App\Http\Controllers\Stock;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\Location;
use App\Models\Product;
use App\Models\QuarantineEntry;
use App\Services\Stock\Exceptions\StockException;
use App\Services\Stock\QuarantineService;
use App\Services\Stock\TransferService;
use App\Support\PackQty;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TransferController extends Controller
{
    public function __construct(private TransferService $transfers, private QuarantineService $quarantine) {}

    public function store(Request $request, Product $product): RedirectResponse
    {
        $data = $request->validate([
            'client_token' => ['required', 'uuid'],
            'from_location_id' => ['required', 'integer', 'exists:locations,id'],
            'to_location_id' => ['required', 'integer', 'exists:locations,id', 'different:from_location_id'],
            'product_unit_id' => ['nullable', 'integer', Rule::exists('product_units', 'id')->where('product_id', $product->id)],
            ...PackQty::rules(),
            'batch_id' => ['nullable', 'integer', 'exists:batches,id'],
            'note' => ['nullable', 'string', 'max:1000'],
            'quarantine_reason' => ['nullable', Rule::in(array_keys(QuarantineEntry::REASONS))],
        ]);

        // Typed in a pack plus loose units, saved as base units.
        $qty = PackQty::toBase($data, $product->units()->whereKey($data['product_unit_id'] ?? 0)->value('base_qty') ?? 1);
        if ($qty === 0) {
            throw ValidationException::withMessages(['qty' => 'Enter a quantity.']);
        }

        $from = Location::findOrFail($data['from_location_id']);
        $to = Location::findOrFail($data['to_location_id']);
        $batch = isset($data['batch_id']) ? Batch::findOrFail($data['batch_id']) : null;
        // Into quarantine from a normal bin: say why (shown on the quarantine register).
        $intoQuarantine = $to->is_quarantine && ! $from->is_quarantine;
        if ($intoQuarantine && empty($data['quarantine_reason'])) {
            throw ValidationException::withMessages(['quarantine_reason' => 'Choose why the stock is going into quarantine.']);
        }

        try {
            $intoQuarantine
                ? $this->quarantine->moveIn($request->user(), $product, $from, $to, $qty, $data['quarantine_reason'], $data['client_token'], $batch, $data['note'] ?? null)
                : $this->transfers->transfer($request->user(), $product, $from, $to, $qty, $data['client_token'], $batch, $data['note'] ?? null);
        } catch (StockException $e) {
            throw ValidationException::withMessages(['qty' => $e->getMessage()]);
        }

        return back()->with('success', $intoQuarantine ? 'Stock moved to quarantine.' : 'Stock moved.');
    }
}
