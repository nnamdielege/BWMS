<?php

namespace App\Http\Controllers\Stock;

use App\Authorization\Permissions;
use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\Customer;
use App\Models\Giveaway;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\ReasonCode;
use App\Models\Setting;
use App\Models\StockBalance;
use App\Models\User;
use App\Services\Stock\Exceptions\StockException;
use App\Services\Stock\GiveawayService;
use App\Services\Stock\StockQuery;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Stock → Give away: samples, gifts, favours, staff and internal use. Managers and admins. */
class GiveawayController extends Controller
{
    public function __construct(private GiveawayService $giveaways, private StockQuery $stock) {}

    public function index(Request $request): Response
    {
        $product = $request->integer('product_id') ? Product::with('units')->find($request->integer('product_id')) : null;
        $tz = config('bwms.business_timezone');
        $monthStart = now($tz)->startOfMonth()->utc();

        return Inertia::render('stock/GiveAway', [
            'reasons' => self::reasons(),
            'approvers' => User::permission(Permissions::STOCK_ADJUST)->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'customers' => Customer::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'product' => $product ? [
                'id' => $product->id, 'sku' => $product->sku, 'name' => $product->name, 'base_unit' => $product->base_unit,
                'avg_cost' => (float) $product->avg_cost, 'is_batch_tracked' => $product->is_batch_tracked,
                'units' => $product->units->sortBy('base_qty')->values()->map(fn (ProductUnit $u) => [
                    'id' => $u->id, 'name' => $u->name, 'base_qty' => (int) $u->base_qty, 'default' => (bool) $u->default_sale,
                ]),
                'slots' => $this->stock->balancesFor($product)
                    ->filter(fn (StockBalance $b) => $b->on_hand > 0 && $b->location->is_active)
                    ->map(fn (StockBalance $b) => [
                        'key' => "{$b->location_id}:{$b->batch_key}",
                        'location_id' => $b->location_id,
                        'batch_id' => $b->batch_id,
                        'label' => $b->location->path().($b->batch ? " · {$b->batch->label()}" : '').($b->location->is_quarantine ? ' (quarantine)' : ''),
                        'on_hand' => $b->on_hand,
                    ])->values(),
            ] : null,
            'recent' => Giveaway::with(['product:id,sku,name', 'unit:id,name', 'reasonCode:id,name', 'customer:id,name', 'approver:id,name', 'creator:id,name'])
                ->latest('occurred_at')->latest('id')->limit(50)->get()
                ->map(fn (Giveaway $g) => [
                    'id' => $g->id,
                    'stock_movement_id' => $g->stock_movement_id,
                    'date' => $g->occurred_at->setTimezone($tz)->format('Y-m-d H:i'),
                    'reason' => $g->reasonCode?->name,
                    'recipient' => $g->recipient(),
                    'product_id' => $g->product_id,
                    'sku' => $g->product?->sku,
                    'name' => $g->product?->name,
                    'qty' => rtrim(rtrim((string) $g->packs, '0'), '.').' × '.($g->unit?->name ?? 'unit'),
                    'value' => (float) $g->value,
                    'purpose' => $g->purpose,
                    'approved_by' => $g->approver?->name,
                    'by' => $g->creator?->name,
                ]),
            'month' => [
                'spent' => round((float) Giveaway::where('occurred_at', '>=', $monthStart)->sum('value'), 2),
                'budget' => Setting::giveawayBudget(),
                'label' => now($tz)->format('F Y'),
            ],
            'can' => ['budget' => $request->user()->can(Permissions::REPORTS_MARGIN)],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'client_token' => ['required', 'uuid'],
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'product_unit_id' => ['nullable', 'integer', 'exists:product_units,id'],
            'packs' => ['required', 'numeric', 'gt:0', 'max:100000'],
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'batch_id' => ['nullable', 'integer', 'exists:batches,id'],
            'reason_code_id' => ['required', 'integer', Rule::exists('reason_codes', 'id')->whereIn('code', ReasonCode::GIVEAWAY_CODES)],
            'customer_id' => ['nullable', 'required_without:recipient_name', 'integer', 'exists:customers,id'],
            'recipient_name' => ['nullable', 'required_without:customer_id', 'string', 'max:255'],
            'purpose' => ['required', 'string', 'max:1000'],
            'approved_by' => ['required', 'integer', 'exists:users,id'],
        ], [
            'customer_id.required_without' => 'Who is it for? Choose a customer or type a name.',
            'recipient_name.required_without' => 'Who is it for? Choose a customer or type a name.',
            'purpose.required' => 'Say what it is for.',
            'approved_by.required' => 'Choose who approved it.',
        ]);

        try {
            $g = $this->giveaways->record(
                $request->user(),
                Product::findOrFail($data['product_id']),
                isset($data['product_unit_id']) ? ProductUnit::find($data['product_unit_id']) : null,
                (float) $data['packs'],
                Location::findOrFail($data['location_id']),
                isset($data['batch_id']) ? Batch::find($data['batch_id']) : null,
                ReasonCode::findOrFail($data['reason_code_id']),
                isset($data['customer_id']) ? Customer::find($data['customer_id']) : null,
                $data['recipient_name'] ?? null,
                $data['purpose'],
                User::findOrFail($data['approved_by']),
                $data['client_token'],
            );
        } catch (StockException $e) {
            throw ValidationException::withMessages(['packs' => $e->getMessage()]);
        }

        return redirect()->route('giveaways.index')->with('success', "Recorded: {$g->qty_base} {$g->product?->base_unit} to {$g->recipient()}.");
    }

    public function budget(Request $request): RedirectResponse
    {
        $data = $request->validate(['budget' => ['nullable', 'numeric', 'min:0', 'max:10000000']]);
        $before = Setting::giveawayBudget();
        $after = isset($data['budget']) ? round((float) $data['budget'], 2) : null;
        Setting::put([Setting::GIVEAWAY_BUDGET => $after === null ? '' : (string) $after]);
        Audit::record('giveaway_budget_changed', null, old: ['budget' => $before], new: ['budget' => $after]);

        return back()->with('success', $after === null ? 'Give-away budget removed.' : 'Give-away budget saved.');
    }

    /** @return list<array{id: int, code: string, name: string}> */
    public static function reasons(): array
    {
        return ReasonCode::whereIn('code', ReasonCode::GIVEAWAY_CODES)->where('is_active', true)->get(['id', 'code', 'name'])
            ->sortBy(fn ($r) => array_search($r->code, ReasonCode::GIVEAWAY_CODES, true))->values()->toArray();
    }
}
