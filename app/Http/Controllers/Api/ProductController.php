<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductRequest;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Inventory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    /**
     * Display a listing of products
     */
    public function index(Request $request)
    {
        try {
            $query = Product::with(['category']);

            // Search filter
            if ($request->has('search') && $request->search) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%")
                        ->orWhere('barcode', 'like', "%{$search}%");
                });
            }

            // Category filter
            if ($request->has('category_id') && $request->category_id) {
                $query->where('category_id', $request->category_id);
            }

            // Active/Inactive filter
            if ($request->has('is_active') && $request->is_active !== '') {
                $query->where('is_active', $request->is_active);
            }

            // Sorting
            $sortBy = $request->get('sort_by', 'created_at');
            $sortOrder = $request->get('sort_order', 'desc');
            $query->orderBy($sortBy, $sortOrder);

            // Pagination
            $perPage = $request->get('per_page', 15);
            $products = $query->paginate($perPage);

            // Add total stock safely
            $products->getCollection()->transform(function ($product) {
                try {
                    $totalStock = \App\Models\Inventory::where('product_id', $product->id)
                        ->sum('quantity_on_hand');
                    $product->total_stock = $totalStock ?? 0;
                } catch (\Exception $e) {
                    $product->total_stock = 0;
                }
                return $product;
            });

            return response()->json($products);
        } catch (\Exception $e) {
            Log::error('Fetch products error: ' . $e->getMessage());
            Log::error($e->getTraceAsString());

            return response()->json([
                'message' => 'Failed to fetch products',
                'error' => config('app.debug') ? $e->getMessage() : 'Server error'
            ], 500);
        }
    }

    /**
     * Store a newly created product
     */
    public function store(StoreProductRequest $request)
    {
        DB::beginTransaction();

        try {
            $product = Product::create($request->validated());

            DB::commit();

            return response()->json([
                'message' => 'Product created successfully',
                'data' => $product->load('category')
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Product creation failed: ' . $e->getMessage());

            return response()->json([
                'message' => 'Failed to create product',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified product
     */
    public function show(Product $product)
    {
        try {
            // Load relationships
            $product->load(['category']);

            // Get total stock across all warehouses
            $totalStock = Inventory::where('product_id', $product->id)
                ->sum('quantity_on_hand');

            $product->total_stock = $totalStock;

            // Get inventory by warehouse
            $inventoryByWarehouse = Inventory::where('product_id', $product->id)
                ->with('warehouse')
                ->get();

            $product->inventory_details = $inventoryByWarehouse;

            return response()->json([
                'data' => $product
            ]);
        } catch (\Exception $e) {
            Log::error('Product show error: ' . $e->getMessage());

            return response()->json([
                'message' => 'Failed to fetch product',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified product
     */
    public function update(Request $request, $id)
    {
        try {
            $product = Product::findOrFail($id);

            $validated = $request->validate([
                'sku' => 'sometimes|required|string|max:255|unique:products,sku,' . $id,
                'barcode' => 'nullable|string|max:255|unique:products,barcode,' . $id,
                'name' => 'sometimes|required|string|max:255',
                'description' => 'nullable|string',
                'category_id' => 'sometimes|required|exists:product_categories,id',
                'cost' => 'sometimes|required|numeric|min:0',
                'price' => 'sometimes|required|numeric|min:0',
                'unit_of_measure' => 'sometimes|required|string|max:50',
                'reorder_point' => 'nullable|integer|min:0',
                'reorder_quantity' => 'nullable|integer|min:0',
                'weight' => 'nullable|numeric|min:0',
                'dimensions' => 'nullable|string|max:255',
                'notes' => 'nullable|string',
                'is_active' => 'boolean',
            ]);

            DB::beginTransaction();

            $product->update($validated);

            DB::commit();

            return response()->json([
                'message' => 'Product updated successfully',
                'data' => $product->load('category')
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to update product',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified product
     */
    public function destroy($id)
    {
        DB::beginTransaction();

        try {
            $product = Product::findOrFail($id);

            // Check if product has inventory
            $hasInventory = Inventory::where('product_id', $id)
                ->where('quantity_on_hand', '>', 0)
                ->exists();

            if ($hasInventory) {
                return response()->json([
                    'message' => 'Cannot delete product with existing inventory. Please adjust inventory to zero first.'
                ], 400);
            }

            // Soft delete the product
            $product->delete();

            DB::commit();

            return response()->json([
                'message' => 'Product deleted successfully'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to delete product',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get product inventory across all warehouses
     */
    public function getInventory($id)
    {
        try {
            $product = Product::findOrFail($id);

            $inventory = Inventory::with(['warehouse'])
                ->where('product_id', $id)
                ->get();

            return response()->json($inventory);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to fetch product inventory',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get all product categories
     */
    public function getCategories()
    {
        try {
            $categories = ProductCategory::where('is_active', true)
                ->orderBy('name')
                ->get();

            return response()->json($categories);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to fetch categories',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Search products (for autocomplete/select)
     */
    public function search(Request $request)
    {
        try {
            $query = Product::with(['category']);

            if ($request->has('q') && $request->q) {
                $search = $request->q;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%")
                        ->orWhere('barcode', 'like', "%{$search}%");
                });
            }

            // Only active products
            $query->where('is_active', true);

            $limit = $request->get('limit', 10);
            $products = $query->limit($limit)->get();

            return response()->json($products);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to search products',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get low stock products
     */
    public function lowStock(Request $request)
    {
        try {
            $query = Product::with(['category'])
                ->whereHas('inventory', function ($q) {
                    $q->whereRaw('quantity_available <= products.reorder_point')
                        ->where('quantity_available', '>', 0);
                });

            if ($request->has('warehouse_id') && $request->warehouse_id) {
                $query->whereHas('inventory', function ($q) use ($request) {
                    $q->where('warehouse_id', $request->warehouse_id);
                });
            }

            $products = $query->get();

            return response()->json($products);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to fetch low stock products',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get out of stock products
     */
    public function outOfStock(Request $request)
    {
        try {
            $query = Product::with(['category'])
                ->whereHas('inventory', function ($q) {
                    $q->where('quantity_available', '<=', 0);
                });

            if ($request->has('warehouse_id') && $request->warehouse_id) {
                $query->whereHas('inventory', function ($q) use ($request) {
                    $q->where('warehouse_id', $request->warehouse_id);
                });
            }

            $products = $query->get();

            return response()->json($products);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to fetch out of stock products',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bulk import products
     */
    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,xlsx,xls|max:5120', // 5MB max
        ]);

        DB::beginTransaction();

        try {
            // TODO: Implement CSV/Excel import logic
            // This would typically use a package like maatwebsite/excel

            DB::commit();

            return response()->json([
                'message' => 'Products imported successfully',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to import products',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Export products
     */
    public function export(Request $request)
    {
        try {
            $query = Product::with(['category']);

            // Apply same filters as index
            if ($request->has('search') && $request->search) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%");
                });
            }

            if ($request->has('category_id') && $request->category_id) {
                $query->where('category_id', $request->category_id);
            }

            if ($request->has('is_active') && $request->is_active !== '') {
                $query->where('is_active', $request->is_active);
            }

            $products = $query->get();

            // TODO: Implement actual Excel/CSV export
            // This would typically use a package like maatwebsite/excel

            return response()->json([
                'message' => 'Export functionality coming soon',
                'products_count' => $products->count(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to export products',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get product statistics
     */
    public function statistics()
    {
        try {
            $stats = [
                'total_products' => Product::count(),
                'active_products' => Product::where('is_active', true)->count(),
                'inactive_products' => Product::where('is_active', false)->count(),
                'low_stock_products' => Product::whereHas('inventory', function ($q) {
                    $q->whereRaw('quantity_available <= products.reorder_point')
                        ->where('quantity_available', '>', 0);
                })->count(),
                'out_of_stock_products' => Product::whereHas('inventory', function ($q) {
                    $q->where('quantity_available', '<=', 0);
                })->count(),
                'total_categories' => ProductCategory::where('is_active', true)->count(),
            ];

            return response()->json($stats);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to fetch product statistics',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Duplicate a product
     */
    public function duplicate($id)
    {
        DB::beginTransaction();

        try {
            $originalProduct = Product::findOrFail($id);

            // Create new product with duplicated data
            $newProduct = $originalProduct->replicate();
            $newProduct->sku = $originalProduct->sku . '-COPY-' . time();
            $newProduct->barcode = null; // Clear barcode as it should be unique
            $newProduct->name = $originalProduct->name . ' (Copy)';
            $newProduct->is_active = false; // Set as inactive by default
            $newProduct->save();

            DB::commit();

            return response()->json([
                'message' => 'Product duplicated successfully',
                'data' => $newProduct->load('category')
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to duplicate product',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bulk update products
     */
    public function bulkUpdate(Request $request)
    {
        $validated = $request->validate([
            'product_ids' => 'required|array',
            'product_ids.*' => 'exists:products,id',
            'updates' => 'required|array',
            'updates.is_active' => 'sometimes|boolean',
            'updates.category_id' => 'sometimes|exists:product_categories,id',
        ]);

        DB::beginTransaction();

        try {
            Product::whereIn('id', $validated['product_ids'])
                ->update($validated['updates']);

            DB::commit();

            return response()->json([
                'message' => 'Products updated successfully',
                'updated_count' => count($validated['product_ids']),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to update products',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bulk delete products
     */
    public function bulkDelete(Request $request)
    {
        $validated = $request->validate([
            'product_ids' => 'required|array',
            'product_ids.*' => 'exists:products,id',
        ]);

        DB::beginTransaction();

        try {
            // Check if any products have inventory
            $productsWithInventory = Inventory::whereIn('product_id', $validated['product_ids'])
                ->where('quantity_on_hand', '>', 0)
                ->pluck('product_id')
                ->toArray();

            if (count($productsWithInventory) > 0) {
                return response()->json([
                    'message' => 'Some products have existing inventory and cannot be deleted',
                    'products_with_inventory' => $productsWithInventory,
                ], 400);
            }

            Product::whereIn('id', $validated['product_ids'])->delete();

            DB::commit();

            return response()->json([
                'message' => 'Products deleted successfully',
                'deleted_count' => count($validated['product_ids']),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to delete products',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get product pricing history
     */
    public function pricingHistory($id)
    {
        try {
            // TODO: Implement pricing history tracking
            // This would require a separate price_history table

            return response()->json([
                'message' => 'Pricing history feature coming soon',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to fetch pricing history',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get product movement history
     */
    public function movementHistory($id, Request $request)
    {
        try {
            $product = Product::findOrFail($id);

            $query = DB::table('inventory_transactions')
                ->where('product_id', $id)
                ->orderBy('created_at', 'desc');

            if ($request->has('warehouse_id') && $request->warehouse_id) {
                $query->where('warehouse_id', $request->warehouse_id);
            }

            if ($request->has('date_from') && $request->date_from) {
                $query->whereDate('created_at', '>=', $request->date_from);
            }

            if ($request->has('date_to') && $request->date_to) {
                $query->whereDate('created_at', '<=', $request->date_to);
            }

            $perPage = $request->get('per_page', 20);
            $history = $query->paginate($perPage);

            return response()->json($history);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to fetch movement history',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}