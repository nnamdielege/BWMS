<?php

namespace App\Http\Controllers\Stock;

use App\Enums\MovementType;
use App\Http\Controllers\Controller;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** The ledger, read-only. */
class MovementController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'sku' => ['nullable', 'string', 'max:64'],
            'type' => ['nullable', Rule::enum(MovementType::class)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);
        $tz = config('bwms.business_timezone');

        $movements = StockMovement::query()
            ->with(['product:id,sku,name', 'location.parent', 'batch', 'reasonCode', 'creator:id,name'])
            ->when($filters['sku'] ?? null, fn ($q, $sku) => $q->whereHas('product', fn ($p) => $p->where('sku', $sku)))
            ->when($filters['type'] ?? null, fn ($q, $t) => $q->where('type', $t))
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->where('occurred_at', '>=', now()->parse($d, $tz)->startOfDay()->utc()))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->where('occurred_at', '<=', now()->parse($d, $tz)->endOfDay()->utc()))
            ->latest('id')
            ->paginate(100)
            ->withQueryString()
            ->through(fn (StockMovement $m) => StockController::movementRow($m));

        return Inertia::render('stock/Movements', [
            'movements' => $movements,
            'filters' => $filters,
            'types' => collect(MovementType::cases())->map(fn ($t) => ['value' => $t->value, 'label' => $t->label()]),
        ]);
    }
}
