<?php

namespace App\Http\Controllers\Orders;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\OrderSubstitution;
use App\Models\PickListLine;
use App\Models\ProductUnit;
use App\Services\Orders\SubstitutionService;
use App\Services\Stock\Exceptions\StockException;
use App\Support\PackQty;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Substitute a line (order page, or a short pick on the handheld), undo it, and tick off the Ordermentum follow-up. */
class SubstitutionController extends Controller
{
    public function __construct(private SubstitutionService $service) {}

    public function store(Request $request, Order $order, OrderLine $line): RedirectResponse
    {
        abort_unless($line->order_id === $order->id, 404);
        $data = $request->validate([
            'replace' => ['required', 'array'],
            ...PackQty::rules('replace.'),
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'product_unit_id' => ['required', 'integer', 'exists:product_units,id'],
            'qty' => ['required', 'array'],
            ...PackQty::rules('qty.'),
            'unit_price' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'keep_original_price' => ['boolean'],
            'note' => ['required', 'string', 'max:500'],
            'pick_list_line_id' => ['nullable', 'integer'],
        ], ['note.required' => 'Say who accepted the substitute, and how (e.g. "Accepted by Jane by phone 14/11").']);

        $unit = ProductUnit::where('product_id', $data['product_id'])->find($data['product_unit_id']);
        if (! $unit) {
            throw ValidationException::withMessages(['product_unit_id' => 'Choose a pack of the substitute.']);
        }
        $shortLine = isset($data['pick_list_line_id']) ? PickListLine::where('order_line_id', $line->id)->find($data['pick_list_line_id']) : null;
        if (isset($data['pick_list_line_id']) && ! $shortLine) {
            throw ValidationException::withMessages(['substitute' => 'That pick line is not for this order line.']);
        }

        try {
            ['substitution' => $sub, 'shortfall' => $short] = $this->service->substitute($line, $request->user(), [
                'replace_base' => PackQty::toBase($data['replace'], $line->base_qty_per_unit),
                'product_id' => (int) $data['product_id'],
                'product_unit_id' => $unit->id,
                'qty_base' => PackQty::toBase($data['qty'], $unit->base_qty),
                'unit_price' => isset($data['unit_price']) ? (string) $data['unit_price'] : null,
                'keep_original_price' => (bool) ($data['keep_original_price'] ?? false),
                'note' => $data['note'],
            ], $shortLine);
        } catch (StockException $e) {
            throw ValidationException::withMessages(['substitute' => $e->getMessage()]);
        }

        $sub->load('substituteProduct');
        $done = "Substituted {$sub->substituteProduct->sku} on {$order->number}.";
        if ($short > 0) {
            return back()->with('error', $done.' Not enough stock to cover it all: '.PackQty::label($short, $unit->base_qty, $unit->name, $sub->substituteProduct->base_unit)
                .' short. It waits for stock like any other line.');
        }

        return back()->with('success', $done);
    }

    public function undo(Request $request, Order $order, OrderSubstitution $substitution): RedirectResponse
    {
        abort_unless($substitution->order_id === $order->id, 404);
        try {
            $this->service->undo($substitution, $request->user());
        } catch (StockException $e) {
            throw ValidationException::withMessages(['substitute' => $e->getMessage()]);
        }

        return back()->with('success', 'Substitution undone; the original line is back.');
    }

    /** Integrations → Sync: "Marked done in Ordermentum". */
    public function channelDone(Request $request, OrderSubstitution $substitution): RedirectResponse
    {
        $done = $request->validate(['done' => ['required', 'boolean']])['done'];
        $this->service->markChannelDone($substitution, $request->user(), (bool) $done);

        return back()->with('success', $done ? 'Marked done in Ordermentum.' : 'Back on the list.');
    }
}
