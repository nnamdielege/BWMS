<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SupplierController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', 'in:active,inactive,all']]);

        return Inertia::render('suppliers/Index', [
            'suppliers' => Supplier::query()
                ->when($filters['q'] ?? null, fn ($q, $t) => $q->where(fn ($w) => $w->where('name', 'like', "%{$t}%")->orWhere('code', 'like', "%{$t}%")))
                ->when(($filters['status'] ?? 'active') !== 'all', fn ($q) => $q->where('is_active', ($filters['status'] ?? 'active') === 'active'))
                ->withCount(['purchaseOrders as open_pos_count' => fn ($q) => $q->whereIn('status', ['draft', 'sent', 'partially_received'])])
                ->orderBy('name')->paginate(50)->withQueryString(),
            'filters' => $filters,
            'canManage' => $request->user()->can('suppliers.manage'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('suppliers/Form', ['supplier' => null]);
    }

    public function store(Request $request): RedirectResponse
    {
        $supplier = Supplier::create($this->validated($request));

        return redirect()->route('suppliers.edit', $supplier)->with('success', "Supplier {$supplier->name} created.");
    }

    public function edit(Supplier $supplier): Response
    {
        return Inertia::render('suppliers/Form', [
            'supplier' => $supplier,
            'recentOrders' => $supplier->purchaseOrders()->latest('id')->limit(10)->get(['id', 'number', 'status', 'order_date', 'total']),
        ]);
    }

    public function update(Request $request, Supplier $supplier): RedirectResponse
    {
        $supplier->update($this->validated($request, $supplier));

        return back()->with('success', "Supplier {$supplier->name} saved.");
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Supplier $supplier = null): array
    {
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Z0-9][A-Z0-9._\-]*$/', Rule::unique('suppliers')->ignore($supplier?->id)],
            'name' => ['required', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'cc_emails' => ['nullable', 'string', 'max:512'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:2000'],
            'payment_terms' => ['nullable', 'string', 'max:100'],
            'lead_time_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['boolean'],
        ]);

        foreach (array_filter(array_map('trim', explode(',', (string) ($data['cc_emails'] ?? '')))) as $cc) {
            if (! filter_var($cc, FILTER_VALIDATE_EMAIL)) {
                throw ValidationException::withMessages(['cc_emails' => "\"{$cc}\" is not a valid email address."]);
            }
        }

        return $data;
    }
}
