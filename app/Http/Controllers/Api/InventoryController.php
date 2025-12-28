<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\Warehouse;
use App\Models\InventoryTransaction;
use App\Services\UsageTrackingService;
use App\Services\InventoryPushService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InventoryController extends Controller
{
    protected $usageService;
    protected $pushService;

    public function __construct(UsageTrackingService $usageService, InventoryPushService $pushService)
    {
        $this->usageService = $usageService;
        $this->pushService = $pushService;
    }

    public function index(Request $request)
    {
        try {
            $query = Inventory::with(['product.category', 'warehouse']);

            // Product filter (for fetching specific inventory)
            if ($request->has('product_id') && $request->product_id) {
                $query->where('product_id', $request->product_id);
            }

            // Warehouse filter
            if ($request->has('warehouse_id') && $request->warehouse_id) {
                $query->where('warehouse_id', $request->warehouse_id);
            }

            // Search filter
            if ($request->has('search') && $request->search) {
                $search = $request->search;
                $query->whereHas('product', function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%");
                });
            }

            // Stock status filter
            if ($request->has('stock_status') && $request->stock_status) {
                $status = $request->stock_status;

                if ($status === 'out_of_stock') {
                    $query->where('quantity_on_hand', '=', 0);
                } elseif ($status === 'low_stock') {
                    $query->whereHas('product', function ($q) {
                        $q->whereColumn('inventory.quantity_on_hand', '<=', 'products.reorder_point')
                            ->where('products.reorder_point', '>', 0);
                    });
                } elseif ($status === 'in_stock') {
                    $query->where('quantity_on_hand', '>', 0);
                }
            }

            // If both product_id and warehouse_id are provided, return single item
            if ($request->has('product_id') && $request->has('warehouse_id')) {
                $inventory = $query->get();
                return response()->json($inventory);
            }

            // Otherwise paginate
            $perPage = $request->get('per_page', 15);
            $inventory = $query->paginate($perPage);

            return response()->json($inventory);
        } catch (\Exception $e) {
            Log::error('Fetch inventory error: ' . $e->getMessage());

            return response()->json([
                'message' => 'Failed to fetch inventory',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Adjust inventory (add/remove stock)
     * AUTO-PUSH: Pushes to Ordermentum if product has Ordermentum IDs
     */
    public function adjust(Request $request)
    {
        // CHECK USAGE LIMIT FIRST
        $canAdjust = $this->usageService->canPerformAction(
            $request->user()->id,
            'inventory_adjusted'
        );

        if (!$canAdjust['allowed']) {
            return response()->json([
                'message' => 'Usage limit exceeded',
                'error' => $canAdjust['reason'],
                'limit' => $canAdjust['limit'] ?? null,
                'current' => $canAdjust['current'] ?? null,
            ], 403);
        }

        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'warehouse_id' => 'required|exists:warehouses,id',
            'quantity' => 'required|integer',
            'type' => 'required|in:adjustment,damage,count',
            'reason' => 'required|string|max:255',
            'notes' => 'nullable|string',
            'push_to_ordermentum' => 'nullable|boolean', // Optional flag to enable/disable push
        ]);

        DB::beginTransaction();

        try {
            // Get or create inventory record
            $inventory = Inventory::firstOrCreate(
                [
                    'product_id' => $validated['product_id'],
                    'warehouse_id' => $validated['warehouse_id'],
                ],
                [
                    'quantity_on_hand' => 0,
                    'quantity_available' => 0,
                    'quantity_allocated' => 0,
                    'quantity_on_order' => 0,
                ]
            );

            $quantityBefore = $inventory->quantity_on_hand;
            $quantityAfter = $quantityBefore + $validated['quantity'];

            // Update inventory
            $inventory->quantity_on_hand = $quantityAfter;
            $inventory->quantity_available = $quantityAfter - $inventory->quantity_allocated;
            $inventory->save();

            // Create transaction record
            InventoryTransaction::create([
                'product_id' => $validated['product_id'],
                'warehouse_id' => $validated['warehouse_id'],
                'type' => $validated['type'],
                'quantity' => $validated['quantity'],
                'quantity_before' => $quantityBefore,
                'quantity_after' => $quantityAfter,
                'reason' => $validated['reason'],
                'notes' => $validated['notes'] ?? null,
                'user_id' => auth()->id(),
            ]);

            // TRACK USAGE
            $this->usageService->track(
                $request->user()->id,
                'inventory_adjusted',
                'inventory',
                $inventory->id,
                [
                    'product_id' => $validated['product_id'],
                    'warehouse_id' => $validated['warehouse_id'],
                    'quantity_change' => $validated['quantity'],
                    'type' => $validated['type'],
                    'reason' => $validated['reason'],
                ]
            );

            // ============================================
            // AUTO-PUSH TO ORDERMENTUM (NEW)
            // ============================================
            $pushSuccessful = false;
            $pushError = null;

            // Check if we should push to Ordermentum
            $shouldPush = $validated['push_to_ordermentum'] ?? true; // Default: true

            if ($shouldPush) {
                try {
                    $pushSuccessful = $this->pushService->pushToOrdermentum($inventory);

                    if ($pushSuccessful) {
                        Log::info('Auto-push to Ordermentum successful', [
                            'inventory_id' => $inventory->id,
                            'product_id' => $validated['product_id'],
                            'quantity' => $quantityAfter,
                        ]);
                    } else {
                        $pushError = 'Failed to push to Ordermentum (check product has Ordermentum IDs)';
                        Log::warning('Auto-push to Ordermentum failed', [
                            'inventory_id' => $inventory->id,
                            'product_id' => $validated['product_id'],
                            'reason' => $pushError,
                        ]);
                    }
                } catch (\Exception $e) {
                    $pushError = $e->getMessage();
                    Log::error('Auto-push to Ordermentum error: ' . $pushError, [
                        'inventory_id' => $inventory->id,
                        'product_id' => $validated['product_id'],
                    ]);
                }
            }
            // ============================================

            DB::commit();

            return response()->json([
                'message' => 'Inventory adjusted successfully',
                'data' => $inventory->load(['product', 'warehouse']),
                'ordermentum_push' => [
                    'attempted' => $shouldPush,
                    'successful' => $pushSuccessful,
                    'error' => $pushError,
                ],
                'usage' => [
                    'remaining' => $canAdjust['remaining'] - 1,
                    'limit' => $canAdjust['limit'],
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Inventory adjustment error: ' . $e->getMessage());

            return response()->json([
                'message' => 'Failed to adjust inventory',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Transfer stock between warehouses
     * AUTO-PUSH: Pushes to Ordermentum from destination warehouse
     */
    public function transfer(Request $request)
    {
        // CHECK USAGE LIMIT FIRST
        $canTransfer = $this->usageService->canPerformAction(
            $request->user()->id,
            'inventory_adjusted'
        );

        if (!$canTransfer['allowed']) {
            return response()->json([
                'message' => 'Usage limit exceeded',
                'error' => $canTransfer['reason'],
                'limit' => $canTransfer['limit'] ?? null,
                'current' => $canTransfer['current'] ?? null,
            ], 403);
        }

        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'from_warehouse_id' => 'required|exists:warehouses,id',
            'to_warehouse_id' => 'required|exists:warehouses,id|different:from_warehouse_id',
            'quantity' => 'required|integer|min:1',
            'notes' => 'nullable|string',
            'push_to_ordermentum' => 'nullable|boolean',
        ]);

        DB::beginTransaction();

        try {
            // Get source inventory
            $fromInventory = Inventory::where('product_id', $validated['product_id'])
                ->where('warehouse_id', $validated['from_warehouse_id'])
                ->firstOrFail();

            // Check if enough stock
            if ($fromInventory->quantity_available < $validated['quantity']) {
                return response()->json([
                    'message' => 'Insufficient stock available for transfer'
                ], 422);
            }

            // Get or create destination inventory
            $toInventory = Inventory::firstOrCreate(
                [
                    'product_id' => $validated['product_id'],
                    'warehouse_id' => $validated['to_warehouse_id'],
                ],
                [
                    'quantity_on_hand' => 0,
                    'quantity_available' => 0,
                    'quantity_allocated' => 0,
                    'quantity_on_order' => 0,
                ]
            );

            // Update source warehouse
            $fromInventory->quantity_on_hand -= $validated['quantity'];
            $fromInventory->quantity_available -= $validated['quantity'];
            $fromInventory->save();

            // Update destination warehouse
            $toInventory->quantity_on_hand += $validated['quantity'];
            $toInventory->quantity_available += $validated['quantity'];
            $toInventory->save();

            // Create transaction records
            InventoryTransaction::create([
                'product_id' => $validated['product_id'],
                'warehouse_id' => $validated['from_warehouse_id'],
                'type' => 'transfer_out',
                'quantity' => -$validated['quantity'],
                'quantity_before' => $fromInventory->quantity_on_hand + $validated['quantity'],
                'quantity_after' => $fromInventory->quantity_on_hand,
                'reason' => 'Transfer to ' . $toInventory->warehouse->name,
                'notes' => $validated['notes'] ?? null,
                'user_id' => auth()->id(),
            ]);

            InventoryTransaction::create([
                'product_id' => $validated['product_id'],
                'warehouse_id' => $validated['to_warehouse_id'],
                'type' => 'transfer_in',
                'quantity' => $validated['quantity'],
                'quantity_before' => $toInventory->quantity_on_hand - $validated['quantity'],
                'quantity_after' => $toInventory->quantity_on_hand,
                'reason' => 'Transfer from ' . $fromInventory->warehouse->name,
                'notes' => $validated['notes'] ?? null,
                'user_id' => auth()->id(),
            ]);

            // TRACK USAGE
            $this->usageService->track(
                $request->user()->id,
                'inventory_adjusted',
                'inventory_transfer',
                $fromInventory->id,
                [
                    'product_id' => $validated['product_id'],
                    'from_warehouse' => $validated['from_warehouse_id'],
                    'to_warehouse' => $validated['to_warehouse_id'],
                    'quantity' => $validated['quantity'],
                ]
            );

            // ============================================
            // AUTO-PUSH TO ORDERMENTUM (NEW)
            // Push from the MAIN warehouse (or specify which warehouse)
            // ============================================
            $pushSuccessful = false;
            $pushError = null;

            $shouldPush = $validated['push_to_ordermentum'] ?? true; // Default: true

            if ($shouldPush) {
                try {
                    // Push from destination warehouse (the one receiving stock)
                    $pushSuccessful = $this->pushService->pushToOrdermentum($toInventory);

                    if ($pushSuccessful) {
                        Log::info('Auto-push to Ordermentum successful after transfer', [
                            'inventory_id' => $toInventory->id,
                            'product_id' => $validated['product_id'],
                            'from_warehouse' => $validated['from_warehouse_id'],
                            'to_warehouse' => $validated['to_warehouse_id'],
                            'quantity' => $toInventory->quantity_on_hand,
                        ]);
                    } else {
                        $pushError = 'Failed to push to Ordermentum';
                        Log::warning('Auto-push to Ordermentum failed after transfer', [
                            'inventory_id' => $toInventory->id,
                            'product_id' => $validated['product_id'],
                        ]);
                    }
                } catch (\Exception $e) {
                    $pushError = $e->getMessage();
                    Log::error('Auto-push to Ordermentum error: ' . $pushError, [
                        'inventory_id' => $toInventory->id,
                    ]);
                }
            }
            // ============================================

            DB::commit();

            return response()->json([
                'message' => 'Stock transferred successfully',
                'data' => [
                    'from' => $fromInventory->load(['product', 'warehouse']),
                    'to' => $toInventory->load(['product', 'warehouse'])
                ],
                'ordermentum_push' => [
                    'attempted' => $shouldPush,
                    'successful' => $pushSuccessful,
                    'error' => $pushError,
                    'pushed_from_warehouse' => $toInventory->warehouse->name,
                ],
                'usage' => [
                    'remaining' => $canTransfer['remaining'] - 1,
                    'limit' => $canTransfer['limit'],
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Inventory transfer error: ' . $e->getMessage());

            return response()->json([
                'message' => 'Failed to transfer stock',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getTransactions(Request $request)
    {
        try {
            $query = InventoryTransaction::with(['product', 'warehouse', 'user']);

            if ($request->has('product_id') && $request->product_id) {
                $query->where('product_id', $request->product_id);
            }

            if ($request->has('warehouse_id') && $request->warehouse_id) {
                $query->where('warehouse_id', $request->warehouse_id);
            }

            $perPage = $request->get('per_page', 15);
            $transactions = $query->orderBy('created_at', 'desc')->paginate($perPage);

            return response()->json($transactions);
        } catch (\Exception $e) {
            Log::error('Fetch transactions error: ' . $e->getMessage());

            return response()->json([
                'message' => 'Failed to fetch transactions',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function lowStock(Request $request)
    {
        try {
            $inventory = Inventory::with(['product.category', 'warehouse'])
                ->whereHas('product', function ($q) {
                    $q->whereColumn('inventory.quantity_on_hand', '<=', 'products.reorder_point')
                        ->where('products.reorder_point', '>', 0);
                })
                ->get();

            return response()->json($inventory);
        } catch (\Exception $e) {
            Log::error('Fetch low stock error: ' . $e->getMessage());

            return response()->json([
                'message' => 'Failed to fetch low stock items',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function outOfStock(Request $request)
    {
        try {
            $inventory = Inventory::with(['product.category', 'warehouse'])
                ->where('quantity_on_hand', 0)
                ->get();

            return response()->json($inventory);
        } catch (\Exception $e) {
            Log::error('Fetch out of stock error: ' . $e->getMessage());

            return response()->json([
                'message' => 'Failed to fetch out of stock items',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
