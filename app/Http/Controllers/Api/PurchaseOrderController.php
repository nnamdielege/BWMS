<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Inventory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PurchaseOrderController extends Controller
{
    public function index(Request $request)
    {
        try {
            $query = PurchaseOrder::with(['supplier', 'warehouse', 'items.product']);

            // Search
            if ($request->has('search') && $request->search) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('order_number', 'like', "%{$search}%")
                        ->orWhereHas('supplier', function ($q) use ($search) {
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
            Log::error('Fetch purchase orders error: ' . $e->getMessage());

            return response()->json([
                'message' => 'Failed to fetch purchase orders',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function store(Request $request)
    {
        Log::info('Purchase order creation request:', $request->all());

        $validated = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'warehouse_id' => 'required|exists:warehouses,id',
            'order_date' => 'required|date',
            'expected_date' => 'nullable|date',
            'status' => 'required|in:draft,pending,received,cancelled',
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
            $orderNumber = 'PO-' . date('Ymd') . '-' . str_pad(PurchaseOrder::whereDate('created_at', today())->count() + 1, 4, '0', STR_PAD_LEFT);

            // Handle empty date fields
            $expectedDate = !empty($validated['expected_date']) ? $validated['expected_date'] : null;
            $notes = !empty($validated['notes']) ? $validated['notes'] : null;

            // Create purchase order
            $order = PurchaseOrder::create([
                'order_number' => $orderNumber,
                'supplier_id' => $validated['supplier_id'],
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

            // Create order items
            foreach ($validated['items'] as $itemData) {
                PurchaseOrderItem::create([
                    'purchase_order_id' => $order->id,
                    'product_id' => $itemData['product_id'],
                    'quantity' => $itemData['quantity'],
                    'received_quantity' => 0,
                    'unit_price' => $itemData['unit_price'],
                    'discount' => $itemData['discount'] ?? 0,
                    'tax' => $itemData['tax'] ?? 0,
                    'subtotal' => $itemData['subtotal'],
                ]);
            }

            DB::commit();

            // Load relationships
            $order->load(['supplier', 'warehouse', 'items.product']);

            Log::info('Purchase order created successfully:', ['order_id' => $order->id]);

            return response()->json([
                'message' => 'Purchase order created successfully',
                'data' => $order
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Purchase order creation failed: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());

            return response()->json([
                'message' => 'Failed to create purchase order',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            $order = PurchaseOrder::with(['supplier', 'warehouse', 'items.product'])
                ->findOrFail($id);

            return response()->json([
                'data' => $order
            ]);
        } catch (\Exception $e) {
            Log::error('Purchase order show error: ' . $e->getMessage());

            return response()->json([
                'message' => 'Purchase order not found',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    public function update(Request $request, $id)
    {
        $order = PurchaseOrder::findOrFail($id);

        if ($order->status === 'received') {
            return response()->json([
                'message' => 'Cannot update received purchase order'
            ], 422);
        }

        $validated = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'warehouse_id' => 'required|exists:warehouses,id',
            'order_date' => 'required|date',
            'expected_date' => 'nullable|date',
            'status' => 'required|in:draft,pending,received,cancelled',
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
            // Handle empty date fields
            $expectedDate = !empty($validated['expected_date']) ? $validated['expected_date'] : null;
            $notes = !empty($validated['notes']) ? $validated['notes'] : null;

            // Update order
            $order->update([
                'supplier_id' => $validated['supplier_id'],
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

            // Create new items
            foreach ($validated['items'] as $itemData) {
                PurchaseOrderItem::create([
                    'purchase_order_id' => $order->id,
                    'product_id' => $itemData['product_id'],
                    'quantity' => $itemData['quantity'],
                    'received_quantity' => 0,
                    'unit_price' => $itemData['unit_price'],
                    'discount' => $itemData['discount'] ?? 0,
                    'tax' => $itemData['tax'] ?? 0,
                    'subtotal' => $itemData['subtotal'],
                ]);
            }

            DB::commit();

            $order->load(['supplier', 'warehouse', 'items.product']);

            return response()->json([
                'message' => 'Purchase order updated successfully',
                'data' => $order
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Purchase order update failed: ' . $e->getMessage());

            return response()->json([
                'message' => 'Failed to update purchase order',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function receive(Request $request, $id)
    {
        $order = PurchaseOrder::with(['items', 'warehouse'])->findOrFail($id);

        if ($order->status === 'received') {
            return response()->json([
                'message' => 'Order is already received'
            ], 422);
        }

        if ($order->status === 'cancelled') {
            return response()->json([
                'message' => 'Cannot receive a cancelled order'
            ], 422);
        }

        $validated = $request->validate([
            'items' => 'required|array',
            'items.*.id' => 'required|exists:purchase_order_items,id',
            'items.*.received_quantity' => 'required|integer|min:0',
        ]);

        DB::beginTransaction();

        try {
            foreach ($validated['items'] as $itemData) {
                $item = PurchaseOrderItem::findOrFail($itemData['id']);

                if ($item->purchase_order_id !== $order->id) {
                    throw new \Exception('Item does not belong to this order');
                }

                $receivedQty = $itemData['received_quantity'];

                // Update item received quantity
                $item->received_quantity += $receivedQty;
                $item->save();

                // Update inventory
                $inventory = Inventory::firstOrCreate(
                    [
                        'product_id' => $item->product_id,
                        'warehouse_id' => $order->warehouse_id,
                    ],
                    [
                        'quantity_on_hand' => 0,
                        'quantity_allocated' => 0,
                    ]
                );

                $inventory->quantity_on_hand += $receivedQty;
                $inventory->save();
            }

            // Check if all items are fully received
            $allReceived = true;
            foreach ($order->items as $item) {
                if ($item->received_quantity < $item->quantity) {
                    $allReceived = false;
                    break;
                }
            }

            if ($allReceived) {
                $order->status = 'received';
                $order->received_date = now();
                $order->save();
            }

            DB::commit();

            $order->load(['supplier', 'warehouse', 'items.product']);

            return response()->json([
                'message' => 'Purchase order received successfully',
                'data' => $order
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Purchase order receive failed: ' . $e->getMessage());

            return response()->json([
                'message' => 'Failed to receive purchase order',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function cancel($id)
    {
        $order = PurchaseOrder::findOrFail($id);

        if ($order->status === 'received') {
            return response()->json([
                'message' => 'Cannot cancel a received order'
            ], 422);
        }

        if ($order->status === 'cancelled') {
            return response()->json([
                'message' => 'Order is already cancelled'
            ], 422);
        }

        DB::beginTransaction();

        try {
            $order->status = 'cancelled';
            $order->save();

            DB::commit();

            $order->load(['supplier', 'warehouse', 'items.product']);

            return response()->json([
                'message' => 'Purchase order cancelled successfully',
                'data' => $order
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Purchase order cancellation failed: ' . $e->getMessage());

            return response()->json([
                'message' => 'Failed to cancel purchase order',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}