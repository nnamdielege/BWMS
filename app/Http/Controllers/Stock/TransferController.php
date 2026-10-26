<?php

namespace App\Http\Controllers\Stock;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\Location;
use App\Models\Product;
use App\Services\Stock\Exceptions\StockException;
use App\Services\Stock\TransferService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TransferController extends Controller
{
    public function __construct(private TransferService $transfers) {}

    public function store(Request $request, Product $product): RedirectResponse
    {
        $data = $request->validate([
            'client_token' => ['required', 'uuid'],
            'from_location_id' => ['required', 'integer', 'exists:locations,id'],
            'to_location_id' => ['required', 'integer', 'exists:locations,id', 'different:from_location_id'],
            'qty' => ['required', 'integer', 'min:1', 'max:1000000'],
            'batch_id' => ['nullable', 'integer', 'exists:batches,id'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $this->transfers->transfer(
                $request->user(), $product,
                Location::findOrFail($data['from_location_id']), Location::findOrFail($data['to_location_id']),
                (int) $data['qty'], $data['client_token'],
                isset($data['batch_id']) ? Batch::findOrFail($data['batch_id']) : null,
                $data['note'] ?? null,
            );
        } catch (StockException $e) {
            throw ValidationException::withMessages(['qty' => $e->getMessage()]);
        }

        return back()->with('success', 'Stock moved.');
    }
}
