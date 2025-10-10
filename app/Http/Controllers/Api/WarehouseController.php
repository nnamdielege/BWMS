<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Warehouse;
use App\Models\Inventory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WarehouseController extends Controller
{
    public function index(Request $request)
    {
        try {
            $query = Warehouse::query();

            // Search filter
            if ($request->has('search') && $request->search) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%")
                        ->orWhere('city', 'like', "%{$search}%");
                });
            }

            // Active filter
            if ($request->has('is_active') && $request->is_active !== '') {
                $query->where('is_active', $request->is_active);
            }

            // Sorting
            $sortBy = $request->get('sort_by', 'name');
            $sortOrder = $request->get('sort_order', 'asc');
            $query->orderBy($sortBy, $sortOrder);

            // Pagination
            $perPage = $request->get('per_page', 15);
            $warehouses = $query->paginate($perPage);

            // Add inventory stats to each warehouse
            $warehouses->getCollection()->transform(function ($warehouse) {
                $warehouse->total_products = Inventory::where('warehouse_id', $warehouse->id)
                    ->distinct('product_id')
                    ->count('product_id');

                $warehouse->total_stock = Inventory::where('warehouse_id', $warehouse->id)
                    ->sum('quantity_on_hand');

                $warehouse->stock_value = Inventory::where('warehouse_id', $warehouse->id)
                    ->join('products', 'inventory.product_id', '=', 'products.id')
                    ->sum(DB::raw('inventory.quantity_on_hand * products.cost'));

                return $warehouse;
            });

            return response()->json($warehouses);
        } catch (\Exception $e) {
            Log::error('Fetch warehouses error: ' . $e->getMessage());

            return response()->json([
                'message' => 'Failed to fetch warehouses',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50|unique:warehouses,code',
            'address' => 'required|string',
            'city' => 'required|string|max:100',
            'state' => 'required|string|max:100',
            'postal_code' => 'required|string|max:20',
            'country' => 'required|string|max:100',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'manager' => 'nullable|string|max:255',
            'is_active' => 'boolean',
        ]);

        DB::beginTransaction();

        try {
            $warehouse = Warehouse::create($validated);

            DB::commit();

            return response()->json([
                'message' => 'Warehouse created successfully',
                'data' => $warehouse
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Warehouse creation failed: ' . $e->getMessage());

            return response()->json([
                'message' => 'Failed to create warehouse',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show(Warehouse $warehouse)
    {
        try {
            // Get inventory stats
            $warehouse->total_products = Inventory::where('warehouse_id', $warehouse->id)
                ->distinct('product_id')
                ->count('product_id');

            $warehouse->total_stock = Inventory::where('warehouse_id', $warehouse->id)
                ->sum('quantity_on_hand');

            $warehouse->stock_value = Inventory::where('warehouse_id', $warehouse->id)
                ->join('products', 'inventory.product_id', '=', 'products.id')
                ->sum(DB::raw('inventory.quantity_on_hand * products.cost'));

            // Get top products
            $warehouse->top_products = Inventory::where('warehouse_id', $warehouse->id)
                ->with('product')
                ->orderBy('quantity_on_hand', 'desc')
                ->limit(10)
                ->get();

            // Get low stock items
            $warehouse->low_stock_items = Inventory::where('warehouse_id', $warehouse->id)
                ->with('product')
                ->whereHas('product', function ($q) {
                    $q->whereColumn('inventory.quantity_on_hand', '<=', 'products.reorder_point')
                        ->where('products.reorder_point', '>', 0);
                })
                ->get();

            return response()->json([
                'data' => $warehouse
            ]);
        } catch (\Exception $e) {
            Log::error('Warehouse show error: ' . $e->getMessage());

            return response()->json([
                'message' => 'Failed to fetch warehouse',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, Warehouse $warehouse)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50|unique:warehouses,code,' . $warehouse->id,
            'address' => 'required|string',
            'city' => 'required|string|max:100',
            'state' => 'required|string|max:100',
            'postal_code' => 'required|string|max:20',
            'country' => 'required|string|max:100',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'manager' => 'nullable|string|max:255',
            'is_active' => 'boolean',
        ]);

        DB::beginTransaction();

        try {
            $warehouse->update($validated);

            DB::commit();

            return response()->json([
                'message' => 'Warehouse updated successfully',
                'data' => $warehouse
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Warehouse update failed: ' . $e->getMessage());

            return response()->json([
                'message' => 'Failed to update warehouse',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy(Warehouse $warehouse)
    {
        try {
            // Check if warehouse has inventory
            $hasInventory = Inventory::where('warehouse_id', $warehouse->id)
                ->where('quantity_on_hand', '>', 0)
                ->exists();

            if ($hasInventory) {
                return response()->json([
                    'message' => 'Cannot delete warehouse with active inventory'
                ], 422);
            }

            $warehouse->delete();

            return response()->json([
                'message' => 'Warehouse deleted successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Warehouse deletion failed: ' . $e->getMessage());

            return response()->json([
                'message' => 'Failed to delete warehouse',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}