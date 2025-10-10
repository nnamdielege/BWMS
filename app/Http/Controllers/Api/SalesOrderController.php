<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\Inventory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SalesOrderController extends Controller
{
    public function index(Request $request)
    {
        try {
            $query = SalesOrder::with(['customer', 'warehouse', 'items.product']);

            // Search
            if ($request->has('search') && $request->search) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('order_number', 'like', "%{$search}%")
                        ->orWhereHas('customer', function ($q) use ($search) {
                            $q->where('company_name', 'like', "%{$search}%");
                        });
                });
            }

            // Status filter
            if ($request->has('status') && $request->status) {
                $query->where('status', $request->status);
            }

            // Date filters
            if ($request->has('date_from') && $request->date_from) {
                $query->whereDate('order_date', '>=', $request->date_from);
            }

            if ($request->has('date_to') && $request->date_to) {
                $query->whereDate('order_date', '<=', $request->date_to);
            }

            // Sorting
            $sortBy = $request->get('sort_by', 'order_date');
            $sortOrder = $request->get('sort_order', 'desc');
            $query->orderBy($sortBy, $sortOrder);

            // Pagination
            $perPage = $request->get('per_page', 15);
            $orders = $query->paginate($perPage);

            return response()->json($orders);
        } catch (\Exception $e) {
            Log::error('Fetch sales orders error: ' . $e->getMessage());

            return response()->json([
                'message' => 'Failed to fetch sales orders',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function store(Request $request)
    {
        Log::info('Sales order creation request:', $request->all());

        $validated = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'warehouse_id' => 'required|exists:warehouses,id',
            'order_date' => 'required|date',
            'expected_date' => 'nullable|date',  // nullable is already here
            'status' => 'required|in:draft,pending,processing,fulfilled,cancelled',
            'subtotal' => 'required|numeric|min:0',
            'tax_rate' => 'nullable|numeric|min:0',
            'tax' => 'nullable|numeric|min:0',
            'shipping' => 'nullable|numeric|min:0',
            'discount' => 'nullable|numeric|min:0',
            'total' => 'required|numeric|min:0',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.discount' => 'nullable|numeric|min:0',
            'items.*.tax' => 'nullable|numeric|min:0',
            'items.*.subtotal' => 'required|numeric|min:0',
        ]);

        DB::beginTransaction();

        try {
            // Generate order number
            $orderNumber = 'SO-' . date('Ymd') . '-' . str_pad(SalesOrder::whereDate('created_at', today())->count() + 1, 4, '0', STR_PAD_LEFT);

            // Convert empty strings to null for date fields
            $expectedDate = !empty($validated['expected_date']) ? $validated['expected_date'] : null;

            // Create sales order
            $order = SalesOrder::create([
                'order_number' => $orderNumber,
                'customer_id' => $validated['customer_id'],
                'warehouse_id' => $validated['warehouse_id'],
                'order_date' => $validated['order_date'],
                'expected_date' => $expectedDate,  // Use the converted value
                'status' => $validated['status'],
                'subtotal' => $validated['subtotal'],
                'tax_rate' => $validated['tax_rate'] ?? 0,
                'tax' => $validated['tax'] ?? 0,
                'shipping' => $validated['shipping'] ?? 0,
                'discount' => $validated['discount'] ?? 0,
                'total' => $validated['total'],
                'notes' => !empty($validated['notes']) ? $validated['notes'] : null,
            ]);

            // Create order items and allocate inventory
            foreach ($validated['items'] as $itemData) {
                // Create order item
                SalesOrderItem::create([
                    'sales_order_id' => $order->id,
                    'product_id' => $itemData['product_id'],
                    'quantity' => $itemData['quantity'],
                    'unit_price' => $itemData['unit_price'],
                    'discount' => $itemData['discount'] ?? 0,
                    'tax' => $itemData['tax'] ?? 0,
                    'subtotal' => $itemData['subtotal'],
                ]);

                // Allocate inventory if status is not draft
                if ($validated['status'] !== 'draft') {
                    $inventory = Inventory::where('product_id', $itemData['product_id'])
                        ->where('warehouse_id', $validated['warehouse_id'])
                        ->first();

                    if (!$inventory) {
                        throw new \Exception("Product not available in selected warehouse");
                    }

                    if ($inventory->quantity_available < $itemData['quantity']) {
                        throw new \Exception("Insufficient stock for product ID: {$itemData['product_id']}");
                    }

                    // Allocate inventory
                    $inventory->quantity_allocated += $itemData['quantity'];
                    $inventory->save();
                }
            }

            DB::commit();

            // Load relationships
            $order->load(['customer', 'warehouse', 'items.product']);

            Log::info('Sales order created successfully:', ['order_id' => $order->id]);

            return response()->json([
                'message' => 'Sales order created successfully',
                'data' => $order
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Sales order creation failed: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());

            return response()->json([
                'message' => 'Failed to create sales order',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            $order = SalesOrder::with(['customer', 'warehouse', 'items.product'])
                ->findOrFail($id);

            return response()->json([
                'data' => $order
            ]);
        } catch (\Exception $e) {
            Log::error('Sales order show error: ' . $e->getMessage());

            return response()->json([
                'message' => 'Sales order not found',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    public function update(Request $request, $id)
    {
        $order = SalesOrder::findOrFail($id);

        if ($order->status !== 'draft' && $order->status !== 'pending') {
            return response()->json([
                'message' => 'Cannot update order that is being processed or fulfilled'
            ], 422);
        }

        $validated = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'warehouse_id' => 'required|exists:warehouses,id',
            'order_date' => 'required|date',
            'expected_date' => 'nullable|date',
            'status' => 'required|in:draft,pending,processing,fulfilled,cancelled',
            'subtotal' => 'required|numeric|min:0',
            'tax_rate' => 'nullable|numeric|min:0',
            'tax' => 'nullable|numeric|min:0',
            'shipping' => 'nullable|numeric|min:0',
            'discount' => 'nullable|numeric|min:0',
            'total' => 'required|numeric|min:0',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.discount' => 'nullable|numeric|min:0',
            'items.*.tax' => 'nullable|numeric|min:0',
            'items.*.subtotal' => 'required|numeric|min:0',
        ]);

        DB::beginTransaction();

        try {
            // Release old allocations
            if ($order->status !== 'draft') {
                foreach ($order->items as $item) {
                    $inventory = Inventory::where('product_id', $item->product_id)
                        ->where('warehouse_id', $order->warehouse_id)
                        ->first();

                    if ($inventory) {
                        $inventory->quantity_allocated -= $item->quantity;
                        $inventory->save();
                    }
                }
            }

            // Convert empty strings to null
            $expectedDate = !empty($validated['expected_date']) ? $validated['expected_date'] : null;
            $notes = !empty($validated['notes']) ? $validated['notes'] : null;

            // Update order
            $order->update([
                'customer_id' => $validated['customer_id'],
                'warehouse_id' => $validated['warehouse_id'],
                'order_date' => $validated['order_date'],
                'expected_date' => $expectedDate,
                'status' => $validated['status'],
                'subtotal' => $validated['subtotal'],
                'tax_rate' => $validated['tax_rate'] ?? 0,
                'tax' => $validated['tax'] ?? 0,
                'shipping' => $validated['shipping'] ?? 0,
                'discount' => $validated['discount'] ?? 0,
                'total' => $validated['total'],
                'notes' => $notes,
            ]);

            // Delete old items
            $order->items()->delete();

            // Create new items and allocate
            foreach ($validated['items'] as $itemData) {
                SalesOrderItem::create([
                    'sales_order_id' => $order->id,
                    'product_id' => $itemData['product_id'],
                    'quantity' => $itemData['quantity'],
                    'unit_price' => $itemData['unit_price'],
                    'discount' => $itemData['discount'] ?? 0,
                    'tax' => $itemData['tax'] ?? 0,
                    'subtotal' => $itemData['subtotal'],
                ]);

                if ($validated['status'] !== 'draft') {
                    $inventory = Inventory::where('product_id', $itemData['product_id'])
                        ->where('warehouse_id', $validated['warehouse_id'])
                        ->first();

                    if (!$inventory || $inventory->quantity_available < $itemData['quantity']) {
                        throw new \Exception("Insufficient stock for product ID: {$itemData['product_id']}");
                    }

                    $inventory->quantity_allocated += $itemData['quantity'];
                    $inventory->save();
                }
            }

            DB::commit();

            $order->load(['customer', 'warehouse', 'items.product']);

            return response()->json([
                'message' => 'Sales order updated successfully',
                'data' => $order
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Sales order update failed: ' . $e->getMessage());

            return response()->json([
                'message' => 'Failed to update sales order',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function fulfill($id)
    {
        $order = SalesOrder::with(['items', 'warehouse'])->findOrFail($id);

        if ($order->status === 'fulfilled') {
            return response()->json([
                'message' => 'Order is already fulfilled'
            ], 422);
        }

        if ($order->status === 'cancelled') {
            return response()->json([
                'message' => 'Cannot fulfill a cancelled order'
            ], 422);
        }

        DB::beginTransaction();

        try {
            foreach ($order->items as $item) {
                $inventory = Inventory::where('product_id', $item->product_id)
                    ->where('warehouse_id', $order->warehouse_id)
                    ->lockForUpdate()
                    ->first();

                if (!$inventory) {
                    throw new \Exception("Inventory not found for product ID: {$item->product_id}");
                }

                // Move from allocated to on_hand reduction
                $inventory->quantity_allocated -= $item->quantity;
                $inventory->quantity_on_hand -= $item->quantity;
                $inventory->save();
            }

            $order->status = 'fulfilled';
            $order->fulfilled_date = now();
            $order->save();

            DB::commit();

            $order->load(['customer', 'warehouse', 'items.product']);

            return response()->json([
                'message' => 'Sales order fulfilled successfully',
                'data' => $order
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Sales order fulfillment failed: ' . $e->getMessage());

            return response()->json([
                'message' => 'Failed to fulfill sales order',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function cancel($id)
    {
        $order = SalesOrder::with(['items', 'warehouse'])->findOrFail($id);

        if ($order->status === 'fulfilled') {
            return response()->json([
                'message' => 'Cannot cancel a fulfilled order'
            ], 422);
        }

        if ($order->status === 'cancelled') {
            return response()->json([
                'message' => 'Order is already cancelled'
            ], 422);
        }

        DB::beginTransaction();

        try {
            // Release allocated inventory
            if ($order->status !== 'draft') {
                foreach ($order->items as $item) {
                    $inventory = Inventory::where('product_id', $item->product_id)
                        ->where('warehouse_id', $order->warehouse_id)
                        ->first();

                    if ($inventory) {
                        $inventory->quantity_allocated -= $item->quantity;
                        $inventory->save();
                    }
                }
            }

            $order->status = 'cancelled';
            $order->save();

            DB::commit();

            $order->load(['customer', 'warehouse', 'items.product']);

            return response()->json([
                'message' => 'Sales order cancelled successfully',
                'data' => $order
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Sales order cancellation failed: ' . $e->getMessage());

            return response()->json([
                'message' => 'Failed to cancel sales order',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}