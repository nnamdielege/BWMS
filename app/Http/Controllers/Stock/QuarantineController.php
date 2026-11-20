<?php

namespace App\Http\Controllers\Stock;

use App\Enums\MovementType;
use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\Location;
use App\Models\Product;
use App\Models\ReasonCode;
use App\Models\Supplier;
use App\Services\Stock\Exceptions\StockException;
use App\Services\Stock\QuarantineRegister;
use App\Services\Stock\QuarantineService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Stock → Quarantine: what's held there and why; write off, return to the supplier, or release. */
class QuarantineController extends Controller
{
    public function __construct(private QuarantineService $quarantine) {}

    public function index(Request $request, QuarantineRegister $register): Response
    {
        return Inertia::render('stock/Quarantine', [
            'register' => $register->build(),
            'reasons' => ReasonCode::where('movement_type', MovementType::WriteOff)->where('is_active', true)->whereNotIn('code', ReasonCode::GIVEAWAY_CODES)->orderBy('name')->get(['id', 'name', 'requires_note']),
            'suppliers' => Supplier::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'release_bins' => Location::sellable()->with('parent.parent')->orderBy('sort_order')->orderBy('code')->get()
                ->map(fn (Location $l) => ['id' => $l->id, 'label' => $l->path()]),
            'can' => ['release' => $request->user()->can('stock.transfer')],
        ]);
    }

    public function writeOff(Request $request): RedirectResponse
    {
        $data = $request->validate($this->lineRules() + [
            'reason_code_id' => ['required', 'integer', Rule::exists('reason_codes', 'id')->where('movement_type', MovementType::WriteOff->value)],
            'note' => ['nullable', 'string', 'max:1000'],
        ], ['reason_code_id.required' => 'Choose a reason.']);
        [$product, $bin, $batch] = $this->line($data);

        $this->run(fn () => $this->quarantine->writeOff($request->user(), $product, $bin, (int) $data['qty'], ReasonCode::findOrFail($data['reason_code_id']),
            $data['client_token'], $batch, $data['note'] ?? null));

        return back()->with('success', 'Written off.');
    }

    public function returnToSupplier(Request $request): RedirectResponse
    {
        $data = $request->validate($this->lineRules() + [
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'note' => ['nullable', 'string', 'max:1000'],
        ], ['supplier_id.required' => 'Choose the supplier.']);
        [$product, $bin, $batch] = $this->line($data);

        $credit = $this->run(fn () => $this->quarantine->returnToSupplier($request->user(), $product, $bin, (int) $data['qty'], Supplier::findOrFail($data['supplier_id']),
            $data['client_token'], $batch, $data['note'] ?? null));

        return back()->with('success', 'Returned. Credit of $'.number_format((float) $credit->amount, 2).' expected from '.$credit->supplier->name.'.');
    }

    public function release(Request $request): RedirectResponse
    {
        $data = $request->validate($this->lineRules() + [
            'to_location_id' => ['required', 'integer', 'exists:locations,id'],
            'note' => ['required', 'string', 'max:1000'],
        ], ['note.required' => 'Say why the stock is fit to sell again.']);
        [$product, $bin, $batch] = $this->line($data);

        $this->run(fn () => $this->quarantine->release($request->user(), $product, $bin, Location::findOrFail($data['to_location_id']), (int) $data['qty'],
            $data['note'], $data['client_token'], $batch));

        return back()->with('success', 'Released back to stock.');
    }

    private function lineRules(): array
    {
        return [
            'client_token' => ['required', 'uuid'],
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'batch_id' => ['nullable', 'integer', 'exists:batches,id'],
            'qty' => ['required', 'integer', 'min:1', 'max:10000000'],
        ];
    }

    /** @return array{0: Product, 1: Location, 2: ?Batch} */
    private function line(array $data): array
    {
        $product = Product::findOrFail($data['product_id']);
        $batch = isset($data['batch_id']) ? Batch::where('product_id', $product->id)->findOrFail($data['batch_id']) : null;

        return [$product, Location::findOrFail($data['location_id']), $batch];
    }

    private function run(callable $action): mixed
    {
        try {
            return $action();
        } catch (StockException $e) {
            throw ValidationException::withMessages(['qty' => $e->getMessage()]);
        }
    }
}
