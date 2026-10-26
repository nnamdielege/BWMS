<?php

namespace App\Http\Controllers\Orders;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CustomerController extends Controller
{
    public function index(Request $request): Response
    {
        $q = $request->validate(['q' => ['nullable', 'string', 'max:100']])['q'] ?? null;

        return Inertia::render('customers/Index', [
            'customers' => Customer::query()
                ->when($q, fn ($b) => $b->where(fn ($w) => $w->where('name', 'like', "%{$q}%")->orWhere('code', 'like', "%{$q}%")))
                ->orderBy('name')->paginate(50)->withQueryString(),
            'filters' => ['q' => $q],
            'canManage' => $request->user()->can('orders.manage'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('customers/Form', ['customer' => null]);
    }

    public function store(Request $request): RedirectResponse
    {
        $customer = Customer::create($this->validated($request));

        return redirect()->route('customers.edit', $customer)->with('success', "Customer {$customer->name} created.");
    }

    public function edit(Customer $customer): Response
    {
        return Inertia::render('customers/Form', ['customer' => $customer]);
    }

    public function update(Request $request, Customer $customer): RedirectResponse
    {
        $customer->update($this->validated($request, $customer));

        return back()->with('success', "Customer {$customer->name} saved.");
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Customer $customer = null): array
    {
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);

        return $request->validate([
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Z0-9][A-Z0-9._\-]*$/', Rule::unique('customers')->ignore($customer?->id)],
            'name' => ['required', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:2000'],
            'ordermentum_retailer_id' => ['nullable', 'string', 'max:64', Rule::unique('customers')->ignore($customer?->id)],
            'portal_user_id' => ['nullable', 'integer', Rule::unique('customers')->ignore($customer?->id)],
            'notes' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['boolean'],
        ]);
    }
}
