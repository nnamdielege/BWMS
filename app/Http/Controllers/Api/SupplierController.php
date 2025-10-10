<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SupplierController extends Controller
{
    public function index(Request $request)
    {
        try {
            $query = Supplier::query();

            // Search filter
            if ($request->has('search') && $request->search) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('company_name', 'like', "%{$search}%")
                        ->orWhere('contact_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('supplier_code', 'like', "%{$search}%");
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
            $suppliers = $query->paginate($perPage);

            return response()->json($suppliers);
        } catch (\Exception $e) {
            Log::error('Fetch suppliers error: ' . $e->getMessage());

            return response()->json([
                'message' => 'Failed to fetch suppliers',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'supplier_code' => 'required|string|max:50|unique:suppliers,supplier_code',
            'company_name' => 'required|string|max:255',
            'contact_name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:suppliers,email',
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
            $supplier = Supplier::create($validated);

            DB::commit();

            return response()->json([
                'message' => 'Supplier created successfully',
                'data' => $supplier
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Supplier creation failed: ' . $e->getMessage());

            return response()->json([
                'message' => 'Failed to create supplier',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show(Supplier $supplier)
    {
        try {
            $supplier->load(['purchaseOrders' => function ($query) {
                $query->latest()->take(10);
            }]);

            // Get statistics
            $stats = [
                'total_purchase_orders' => $supplier->purchaseOrders()->count(),
                'pending_orders' => $supplier->purchaseOrders()->where('status', 'pending')->count(),
                'total_amount' => $supplier->purchaseOrders()->sum('total'),
            ];

            return response()->json([
                'data' => $supplier,
                'stats' => $stats
            ]);
        } catch (\Exception $e) {
            Log::error('Supplier show error: ' . $e->getMessage());

            return response()->json([
                'message' => 'Failed to fetch supplier',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, Supplier $supplier)
    {
        $validated = $request->validate([
            'supplier_code' => 'required|string|max:50|unique:suppliers,supplier_code,' . $supplier->id,
            'company_name' => 'required|string|max:255',
            'contact_name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:suppliers,email,' . $supplier->id,
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
            $supplier->update($validated);

            DB::commit();

            return response()->json([
                'message' => 'Supplier updated successfully',
                'data' => $supplier
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Supplier update failed: ' . $e->getMessage());

            return response()->json([
                'message' => 'Failed to update supplier',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy(Supplier $supplier)
    {
        try {
            // Check if supplier has purchase orders
            if ($supplier->purchaseOrders()->exists()) {
                return response()->json([
                    'message' => 'Cannot delete supplier with existing purchase orders'
                ], 422);
            }

            $supplier->delete();

            return response()->json([
                'message' => 'Supplier deleted successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Supplier deletion failed: ' . $e->getMessage());

            return response()->json([
                'message' => 'Failed to delete supplier',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}