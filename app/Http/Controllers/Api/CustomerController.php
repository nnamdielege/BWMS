<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        try {
            $query = Customer::query();

            // Search filter
            if ($request->has('search') && $request->search) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('company_name', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('customer_code', 'like', "%{$search}%");
                });
            }

            // Active filter
            if ($request->has('is_active') && $request->is_active !== '') {
                $query->where('is_active', $request->is_active);
            }

            // Sorting
            $sortBy = $request->get('sort_by', 'company_name');
            $sortOrder = $request->get('sort_order', 'asc');
            $query->orderBy($sortBy, $sortOrder);

            // Pagination
            $perPage = $request->get('per_page', 15);
            $customers = $query->paginate($perPage);

            return response()->json($customers);
        } catch (\Exception $e) {
            Log::error('Fetch customers error: ' . $e->getMessage());

            return response()->json([
                'message' => 'Failed to fetch customers',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'customer_code' => 'required|string|max:50|unique:customers,customer_code',
            'company_name' => 'required|string|max:255',
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:customers,email',
            'phone' => 'nullable|string|max:50',
            'mobile' => 'nullable|string|max:50',
            'address' => 'required|string',
            'city' => 'required|string|max:100',
            'state' => 'required|string|max:100',
            'postal_code' => 'required|string|max:20',
            'country' => 'required|string|max:100',
            'tax_id' => 'nullable|string|max:50',
            'payment_terms' => 'nullable|string|max:100',
            'credit_limit' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        DB::beginTransaction();

        try {
            $customer = Customer::create($validated);

            DB::commit();

            return response()->json([
                'message' => 'Customer created successfully',
                'data' => $customer
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Customer creation failed: ' . $e->getMessage());

            return response()->json([
                'message' => 'Failed to create customer',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show(Customer $customer)
    {
        try {
            $customer->load(['salesOrders' => function ($query) {
                $query->latest()->take(10);
            }]);

            // Get statistics
            $stats = [
                'total_orders' => $customer->salesOrders()->count(),
                'pending_orders' => $customer->salesOrders()->where('status', 'pending')->count(),
                'total_amount' => $customer->salesOrders()->sum('total'),
            ];

            return response()->json([
                'data' => $customer,
                'stats' => $stats
            ]);
        } catch (\Exception $e) {
            Log::error('Customer show error: ' . $e->getMessage());

            return response()->json([
                'message' => 'Failed to fetch customer',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, Customer $customer)
    {
        $validated = $request->validate([
            'customer_code' => 'required|string|max:50|unique:customers,customer_code,' . $customer->id,
            'company_name' => 'required|string|max:255',
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:customers,email,' . $customer->id,
            'phone' => 'nullable|string|max:50',
            'mobile' => 'nullable|string|max:50',
            'address' => 'required|string',
            'city' => 'required|string|max:100',
            'state' => 'required|string|max:100',
            'postal_code' => 'required|string|max:20',
            'country' => 'required|string|max:100',
            'tax_id' => 'nullable|string|max:50',
            'payment_terms' => 'nullable|string|max:100',
            'credit_limit' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        DB::beginTransaction();

        try {
            $customer->update($validated);

            DB::commit();

            return response()->json([
                'message' => 'Customer updated successfully',
                'data' => $customer
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Customer update failed: ' . $e->getMessage());

            return response()->json([
                'message' => 'Failed to update customer',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy(Customer $customer)
    {
        try {
            // Check if customer has orders
            if ($customer->salesOrders()->exists()) {
                return response()->json([
                    'message' => 'Cannot delete customer with existing orders'
                ], 422);
            }

            $customer->delete();

            return response()->json([
                'message' => 'Customer deleted successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Customer deletion failed: ' . $e->getMessage());

            return response()->json([
                'message' => 'Failed to delete customer',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}