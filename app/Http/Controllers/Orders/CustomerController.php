<?php

namespace App\Http\Controllers\Orders;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\DeliveryArea;
use App\Models\Order;
use App\Services\Delivery\DeliveryScheduler;
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
        return Inertia::render('customers/Form', ['customer' => null, 'deliveryAreas' => $this->areas()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $customer = Customer::create($this->validated($request));

        return redirect()->route('customers.edit', $customer)->with('success', "Customer {$customer->name} created.");
    }

    public function edit(Customer $customer): Response
    {
        return Inertia::render('customers/Form', ['customer' => $customer, 'deliveryAreas' => $this->areas()]);
    }

    public function update(Request $request, Customer $customer, DeliveryScheduler $scheduler): RedirectResponse
    {
        $customer->update($this->validated($request, $customer));
        // A new address or area moves the customer's waiting orders to the right run.
        if ($customer->wasChanged(['delivery_postcode', 'delivery_suburb', 'delivery_area_id'])) {
            $customer->orders()->whereIn('status', [OrderStatus::Open, OrderStatus::PartiallyDispatched])->get()->each(fn (Order $o) => $scheduler->assign($o));
        }

        return back()->with('success', "Customer {$customer->name} saved.");
    }

    private function areas()
    {
        return DeliveryArea::where('is_active', true)->orderBy('sort_order')->get(['id', 'name']);
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
            'delivery_street' => ['nullable', 'string', 'max:255'],
            'delivery_suburb' => ['nullable', 'string', 'max:100'],
            'delivery_postcode' => ['nullable', 'regex:/^\d{4}$/'],
            'delivery_area_id' => ['nullable', 'integer', Rule::exists('delivery_areas', 'id')],
            'ordermentum_retailer_id' => ['nullable', 'string', 'max:64', Rule::unique('customers')->ignore($customer?->id)],
            'portal_user_id' => ['nullable', 'integer', Rule::unique('customers')->ignore($customer?->id)],
            'notes' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['boolean'],
        ]);
    }
}
