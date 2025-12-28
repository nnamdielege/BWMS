<?php

namespace App\Services;

use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Inventory;
use App\Models\InventoryTransaction;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class OrderService
{
    protected $inventoryService;
    protected $pushService;

    public function __construct(InventoryService $inventoryService, InventoryPushService $pushService = null)
    {
        $this->inventoryService = $inventoryService;
        $this->pushService = $pushService;
    }

    /**
     * Create a new sales order
     */
    public function createSalesOrder(array $data)
    {
        return DB::transaction(function () use ($data) {
            // Generate order number
            $orderNumber = $this->generateSalesOrderNumber();

            // Calculate totals
            $subtotal = 0;
            foreach ($data['items'] as $item) {
                $subtotal += $item['quantity'] * $item['unit_price'];
            }

            $tax = $subtotal * ($data['tax_rate'] ?? 0);
            $total = $subtotal + $tax + ($data['shipping'] ?? 0) - ($data['discount'] ?? 0);

            // Create order
            $order = SalesOrder::create([
                'order_number' => $orderNumber,
                'customer_id' => $data['customer_id'],
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'order_date' => $data['order_date'] ?? now(),
                'required_date' => $data['required_date'] ?? null,
                'status' => $data['status'] ?? 'draft',
                'subtotal' => $subtotal,
                'tax' => $tax,
                'shipping' => $data['shipping'] ?? 0,
                'discount' => $data['discount'] ?? 0,
                'total' => $total,
                'notes' => $data['notes'] ?? null,
                'shipping_address' => $data['shipping_address'] ?? null,
            ]);

            // Create order items and allocate inventory
            foreach ($data['items'] as $itemData) {
                $this->addSalesOrderItem($order, $itemData);
            }

            return $order->fresh()->load(['items.product', 'customer', 'warehouse']);
        });
    }

    /**
     * Add item to sales order
     */
    protected function addSalesOrderItem(SalesOrder $order, array $itemData)
    {
        $product = Product::findOrFail($itemData['product_id']);
        $quantity = $itemData['quantity'];
        $unitPrice = $itemData['unit_price'] ?? $product->price;

        // Create order item
        $orderItem = SalesOrderItem::create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'quantity_fulfilled' => 0,
            'unit_price' => $unitPrice,
            'subtotal' => $quantity * $unitPrice,
            'notes' => $itemData['notes'] ?? null,
        ]);

        // Allocate inventory if order is not draft
        if ($order->status !== 'draft' && $order->warehouse_id) {
            $this->allocateInventory($product->id, $order->warehouse_id, $quantity);
        }

        return $orderItem;
    }

    /**
     * Allocate inventory for an order
     */
    protected function allocateInventory($productId, $warehouseId, $quantity)
    {
        $inventory = Inventory::where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->lockForUpdate()
            ->first();

        if (!$inventory) {
            throw new \Exception("Product not found in warehouse inventory");
        }

        if ($inventory->quantity_available < $quantity) {
            throw new \Exception("Insufficient inventory available. Available: {$inventory->quantity_available}, Required: {$quantity}");
        }

        $inventory->increment('quantity_allocated', $quantity);
        $inventory->decrement('quantity_available', $quantity);

        return $inventory;
    }

    /**
     * Fulfill a sales order with auto-push to Ordermentum
     */
    public function fulfillSalesOrder(SalesOrder $order, array $data = [])
    {
        return DB::transaction(function () use ($order, $data) {
            if ($order->status === 'completed') {
                throw new \Exception('Order is already completed');
            }

            if (!$order->warehouse_id) {
                throw new \Exception('Order must have a warehouse assigned');
            }

            $fullyFulfilled = true;
            $inventoriesUpdated = []; // Track updated inventories for push

            foreach ($order->items as $item) {
                $quantityToFulfill = $data['items'][$item->id]['quantity'] ??
                    ($item->quantity - $item->quantity_fulfilled);

                if ($quantityToFulfill > 0) {
                    // Update item
                    $item->increment('quantity_fulfilled', $quantityToFulfill);

                    // Reduce inventory
                    $inventory = Inventory::where('product_id', $item->product_id)
                        ->where('warehouse_id', $order->warehouse_id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    // Reduce allocated and on-hand quantities
                    $inventory->decrement('quantity_allocated', $quantityToFulfill);
                    $inventory->decrement('quantity_on_hand', $quantityToFulfill);

                    // Record transaction
                    InventoryTransaction::create([
                        'product_id' => $item->product_id,
                        'warehouse_id' => $order->warehouse_id,
                        'type' => 'out',
                        'quantity' => $quantityToFulfill,
                        'quantity_before' => $inventory->quantity_on_hand + $quantityToFulfill,
                        'quantity_after' => $inventory->quantity_on_hand,
                        'reason' => 'sales_order',
                        'reference' => "Sales Order #{$order->order_number}",
                        'user_id' => auth()->id(),
                    ]);

                    // Track for push
                    $inventoriesUpdated[$inventory->id] = $inventory;
                }

                // Check if item is fully fulfilled
                if ($item->quantity_fulfilled < $item->quantity) {
                    $fullyFulfilled = false;
                }
            }

            // Update order status
            if ($fullyFulfilled) {
                $order->update([
                    'status' => 'completed',
                    'shipped_date' => now(),
                ]);
            } else {
                $order->update([
                    'status' => 'processing',
                ]);
            }

            // ============================================
            // AUTO-PUSH TO ORDERMENTUM (NEW)
            // ============================================
            $pushResults = [];

            $shouldPush = $data['push_to_ordermentum'] ?? true;

            if ($shouldPush && $this->pushService && !empty($inventoriesUpdated)) {
                foreach ($inventoriesUpdated as $inventory) {
                    try {
                        $pushSuccessful = $this->pushService->pushToOrdermentum($inventory);

                        $pushResults[$inventory->id] = [
                            'attempted' => true,
                            'successful' => $pushSuccessful,
                            'error' => $pushSuccessful ? null : 'Failed to push',
                            'product_id' => $inventory->product_id,
                        ];

                        if ($pushSuccessful) {
                            Log::info('Auto-push to Ordermentum successful after sales order fulfillment', [
                                'order_id' => $order->id,
                                'order_number' => $order->order_number,
                                'inventory_id' => $inventory->id,
                                'product_id' => $inventory->product_id,
                                'quantity' => $inventory->quantity_on_hand,
                            ]);
                        } else {
                            Log::warning('Auto-push to Ordermentum failed after sales order fulfillment', [
                                'order_id' => $order->id,
                                'inventory_id' => $inventory->id,
                                'product_id' => $inventory->product_id,
                            ]);
                        }
                    } catch (\Exception $e) {
                        $pushResults[$inventory->id] = [
                            'attempted' => true,
                            'successful' => false,
                            'error' => $e->getMessage(),
                            'product_id' => $inventory->product_id,
                        ];

                        Log::error('Auto-push to Ordermentum error after sales order fulfillment: ' . $e->getMessage(), [
                            'order_id' => $order->id,
                            'inventory_id' => $inventory->id,
                        ]);
                    }
                }
            }
            // ============================================

            $orderFresh = $order->fresh()->load(['items.product', 'customer']);
            $orderFresh->ordermentum_push_results = $pushResults;

            return $orderFresh;
        });
    }

    /**
     * Cancel a sales order
     */
    public function cancelSalesOrder(SalesOrder $order)
    {
        return DB::transaction(function () use ($order) {
            if ($order->status === 'completed') {
                throw new \Exception('Cannot cancel a completed order');
            }

            // Release allocated inventory
            if ($order->warehouse_id) {
                foreach ($order->items as $item) {
                    $allocatedQuantity = $item->quantity - $item->quantity_fulfilled;

                    if ($allocatedQuantity > 0) {
                        $inventory = Inventory::where('product_id', $item->product_id)
                            ->where('warehouse_id', $order->warehouse_id)
                            ->lockForUpdate()
                            ->firstOrFail();

                        $inventory->decrement('quantity_allocated', $allocatedQuantity);
                        $inventory->increment('quantity_available', $allocatedQuantity);
                    }
                }
            }

            $order->update(['status' => 'cancelled']);

            return $order;
        });
    }

    /**
     * Create a purchase order with auto-push to Ordermentum
     */
    public function createPurchaseOrder(array $data)
    {
        return DB::transaction(function () use ($data) {
            // Generate order number
            $orderNumber = $this->generatePurchaseOrderNumber();

            // Calculate totals
            $subtotal = 0;
            foreach ($data['items'] as $item) {
                $subtotal += $item['quantity'] * $item['unit_price'];
            }

            $tax = $subtotal * ($data['tax_rate'] ?? 0);
            $total = $subtotal + $tax + ($data['shipping'] ?? 0) - ($data['discount'] ?? 0);

            // Create order
            $order = PurchaseOrder::create([
                'order_number' => $orderNumber,
                'supplier_id' => $data['supplier_id'],
                'warehouse_id' => $data['warehouse_id'],
                'order_date' => $data['order_date'] ?? now(),
                'expected_date' => $data['expected_date'] ?? null,
                'status' => $data['status'] ?? 'draft',
                'subtotal' => $subtotal,
                'tax' => $tax,
                'shipping' => $data['shipping'] ?? 0,
                'discount' => $data['discount'] ?? 0,
                'total' => $total,
                'notes' => $data['notes'] ?? null,
            ]);

            // Create order items
            foreach ($data['items'] as $itemData) {
                PurchaseOrderItem::create([
                    'purchase_order_id' => $order->id,
                    'product_id' => $itemData['product_id'],
                    'quantity' => $itemData['quantity'],
                    'quantity_received' => 0,
                    'unit_price' => $itemData['unit_price'],
                    'subtotal' => $itemData['quantity'] * $itemData['unit_price'],
                    'notes' => $itemData['notes'] ?? null,
                ]);
            }

            // Update inventory on_order quantity
            foreach ($data['items'] as $itemData) {
                Inventory::where('product_id', $itemData['product_id'])
                    ->where('warehouse_id', $data['warehouse_id'])
                    ->increment('quantity_on_order', $itemData['quantity']);
            }

            return $order->fresh()->load(['items.product', 'supplier', 'warehouse']);
        });
    }

    /**
     * Receive a purchase order with auto-push to Ordermentum
     */
    public function receivePurchaseOrder(PurchaseOrder $order, array $data)
    {
        return DB::transaction(function () use ($order, $data) {
            if (!$order->warehouse_id) {
                throw new \Exception('Purchase order must have a warehouse assigned');
            }

            $fullyReceived = true;
            $inventoriesUpdated = []; // Track updated inventories for push

            foreach ($data['items'] as $itemId => $receivingData) {
                $item = PurchaseOrderItem::findOrFail($itemId);

                if ($item->purchase_order_id !== $order->id) {
                    throw new \Exception('Item does not belong to this purchase order');
                }

                $quantityToReceive = $receivingData['quantity'];

                if ($quantityToReceive > ($item->quantity - $item->quantity_received)) {
                    throw new \Exception('Cannot receive more than ordered quantity');
                }

                // Update item
                $item->increment('quantity_received', $quantityToReceive);

                // Update inventory
                $inventory = Inventory::where('product_id', $item->product_id)
                    ->where('warehouse_id', $order->warehouse_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $oldQuantity = $inventory->quantity_on_hand;

                $inventory->increment('quantity_on_hand', $quantityToReceive);
                $inventory->increment('quantity_available', $quantityToReceive);
                $inventory->decrement('quantity_on_order', $quantityToReceive);

                // Update bin location if provided
                if (isset($receivingData['bin_location'])) {
                    $inventory->update(['bin_location' => $receivingData['bin_location']]);
                }

                // Record transaction
                InventoryTransaction::create([
                    'product_id' => $item->product_id,
                    'warehouse_id' => $order->warehouse_id,
                    'type' => 'in',
                    'quantity' => $quantityToReceive,
                    'quantity_before' => $oldQuantity,
                    'quantity_after' => $inventory->quantity_on_hand,
                    'reason' => 'purchase_order',
                    'reference' => "Purchase Order #{$order->order_number}",
                    'user_id' => auth()->id(),
                ]);

                // Track for push
                $inventoriesUpdated[$inventory->id] = $inventory;

                // Check if item is fully received
                if ($item->quantity_received < $item->quantity) {
                    $fullyReceived = false;
                }
            }

            // Update order status
            if ($fullyReceived) {
                $order->update([
                    'status' => 'completed',
                    'received_date' => now(),
                ]);
            } else {
                $order->update([
                    'status' => 'partial',
                ]);
            }

            // ============================================
            // AUTO-PUSH TO ORDERMENTUM (NEW)
            // ============================================
            $pushResults = [];

            $shouldPush = $data['push_to_ordermentum'] ?? true;

            if ($shouldPush && $this->pushService && !empty($inventoriesUpdated)) {
                foreach ($inventoriesUpdated as $inventory) {
                    try {
                        $pushSuccessful = $this->pushService->pushToOrdermentum($inventory);

                        $pushResults[$inventory->id] = [
                            'attempted' => true,
                            'successful' => $pushSuccessful,
                            'error' => $pushSuccessful ? null : 'Failed to push',
                            'product_id' => $inventory->product_id,
                            'quantity' => $inventory->quantity_on_hand,
                        ];

                        if ($pushSuccessful) {
                            Log::info('Auto-push to Ordermentum successful after purchase order receipt', [
                                'order_id' => $order->id,
                                'order_number' => $order->order_number,
                                'inventory_id' => $inventory->id,
                                'product_id' => $inventory->product_id,
                                'quantity' => $inventory->quantity_on_hand,
                            ]);
                        } else {
                            Log::warning('Auto-push to Ordermentum failed after purchase order receipt', [
                                'order_id' => $order->id,
                                'inventory_id' => $inventory->id,
                                'product_id' => $inventory->product_id,
                            ]);
                        }
                    } catch (\Exception $e) {
                        $pushResults[$inventory->id] = [
                            'attempted' => true,
                            'successful' => false,
                            'error' => $e->getMessage(),
                            'product_id' => $inventory->product_id,
                        ];

                        Log::error('Auto-push to Ordermentum error after purchase order receipt: ' . $e->getMessage(), [
                            'order_id' => $order->id,
                            'inventory_id' => $inventory->id,
                        ]);
                    }
                }
            }
            // ============================================

            $orderFresh = $order->fresh()->load(['items.product', 'supplier']);
            $orderFresh->ordermentum_push_results = $pushResults;

            return $orderFresh;
        });
    }

    /**
     * Cancel a purchase order
     */
    public function cancelPurchaseOrder(PurchaseOrder $order)
    {
        return DB::transaction(function () use ($order) {
            if ($order->status === 'completed') {
                throw new \Exception('Cannot cancel a completed order');
            }

            // Update inventory on_order quantity
            foreach ($order->items as $item) {
                $pendingQuantity = $item->quantity - $item->quantity_received;

                if ($pendingQuantity > 0) {
                    Inventory::where('product_id', $item->product_id)
                        ->where('warehouse_id', $order->warehouse_id)
                        ->decrement('quantity_on_order', $pendingQuantity);
                }
            }

            $order->update(['status' => 'cancelled']);

            return $order;
        });
    }

    /**
     * Generate sales order number
     */
    protected function generateSalesOrderNumber()
    {
        $lastOrder = SalesOrder::latest('id')->first();
        $number = $lastOrder ? intval(substr($lastOrder->order_number, 3)) + 1 : 1;

        return 'SO-' . str_pad($number, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Generate purchase order number
     */
    protected function generatePurchaseOrderNumber()
    {
        $lastOrder = PurchaseOrder::latest('id')->first();
        $number = $lastOrder ? intval(substr($lastOrder->order_number, 3)) + 1 : 1;

        return 'PO-' . str_pad($number, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Get order statistics
     */
    public function getOrderStatistics($startDate = null, $endDate = null)
    {
        $startDate = $startDate ?? now()->startOfMonth();
        $endDate = $endDate ?? now()->endOfMonth();

        return [
            'sales_orders' => [
                'total' => SalesOrder::whereBetween('order_date', [$startDate, $endDate])->count(),
                'pending' => SalesOrder::where('status', 'pending')->count(),
                'processing' => SalesOrder::where('status', 'processing')->count(),
                'completed' => SalesOrder::where('status', 'completed')->count(),
                'total_value' => SalesOrder::whereBetween('order_date', [$startDate, $endDate])->sum('total'),
            ],
            'purchase_orders' => [
                'total' => PurchaseOrder::whereBetween('order_date', [$startDate, $endDate])->count(),
                'pending' => PurchaseOrder::where('status', 'pending')->count(),
                'partial' => PurchaseOrder::where('status', 'partial')->count(),
                'completed' => PurchaseOrder::where('status', 'completed')->count(),
                'total_value' => PurchaseOrder::whereBetween('order_date', [$startDate, $endDate])->sum('total'),
            ],
        ];
    }

    /**
     * Set the push service (for dependency injection)
     */
    public function setPushService(InventoryPushService $pushService)
    {
        $this->pushService = $pushService;
        return $this;
    }
}
