<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\OrdermentumService;
use App\Models\OrdermentumConnection;
use Illuminate\Http\Request;

class OrdermentumController extends Controller
{
    public function connect(Request $request)
    {
        $request->validate([
            'code' => 'required|string',
        ]);

        try {
            $service = new OrdermentumService();
            $connection = $service->connect($request->code);

            return response()->json([
                'message' => 'Successfully connected to Ordermentum',
                'connection' => $connection,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to connect to Ordermentum',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function syncOrders(Request $request)
    {
        $connection = OrdermentumConnection::where('supplier_id', $request->supplier_id)->first();

        if (!$connection) {
            return response()->json(['message' => 'No Ordermentum connection found'], 404);
        }

        try {
            $service = new OrdermentumService($connection);
            $orders = $service->getOrders([
                'status' => 'pending',
                'since' => $connection->last_sync_at ?? now()->subDays(7),
            ]);

            // Process and create sales orders
            $syncedCount = 0;
            foreach ($orders['data'] as $orderData) {
                $this->createSalesOrderFromOrdermentum($orderData, $connection);
                $syncedCount++;
            }

            $connection->update([
                'last_sync_at' => now(),
                'sync_status' => 'success',
            ]);

            return response()->json([
                'message' => "Successfully synced {$syncedCount} orders",
                'synced_count' => $syncedCount,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to sync orders',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function syncInventory(Request $request)
    {
        $connection = OrdermentumConnection::first();

        if (!$connection) {
            return response()->json(['message' => 'No Ordermentum connection found'], 404);
        }

        try {
            $service = new OrdermentumService($connection);

            // Get all product mappings
            $mappings = $connection->productMappings()->with('product.inventory')->get();

            $syncedCount = 0;
            foreach ($mappings as $mapping) {
                $totalQuantity = $mapping->product->inventory->sum('quantity_available');
                $service->updateProductInventory($mapping->ordermentum_product_id, $totalQuantity);
                $syncedCount++;
            }

            return response()->json([
                'message' => "Successfully synced inventory for {$syncedCount} products",
                'synced_count' => $syncedCount,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to sync inventory',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function status()
    {
        $connection = OrdermentumConnection::first();

        if (!$connection) {
            return response()->json([
                'connected' => false,
                'message' => 'No Ordermentum connection found',
            ]);
        }

        return response()->json([
            'connected' => true,
            'supplier_id' => $connection->supplier_id,
            'last_sync_at' => $connection->last_sync_at,
            'sync_status' => $connection->sync_status,
        ]);
    }

    public function createSalesOrderFromOrdermentum($orderData, $connection)
    {
        // Check if order already exists
        $existingOrder = $connection->orders()
            ->where('ordermentum_order_id', $orderData['id'])
            ->first();

        if ($existingOrder) {
            return $existingOrder->salesOrder;
        }

        // Create sales order
        $salesOrder = \App\Models\SalesOrder::create([
            'customer_id' => $this->getOrCreateCustomer($orderData['customer']),
            'order_number' => $orderData['reference'],
            'order_date' => $orderData['created_at'],
            'status' => 'pending',
            'subtotal' => $orderData['subtotal'],
            'tax' => $orderData['tax'],
            'total' => $orderData['total'],
            'notes' => $orderData['notes'] ?? null,
        ]);

        // Create order items
        foreach ($orderData['items'] as $item) {
            $mapping = $connection->productMappings()
                ->where('ordermentum_product_id', $item['product_id'])
                ->first();

            if ($mapping) {
                $salesOrder->items()->create([
                    'product_id' => $mapping->internal_product_id,
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'subtotal' => $item['subtotal'],
                ]);
            }
        }

        // Link to Ordermentum
        $connection->orders()->create([
            'ordermentum_order_id' => $orderData['id'],
            'sales_order_id' => $salesOrder->id,
            'sync_status' => 'synced',
            'raw_data' => json_encode($orderData),
            'synced_at' => now(),
        ]);

        return $salesOrder;
    }

    protected function getOrCreateCustomer($customerData)
    {
        return \App\Models\Customer::firstOrCreate(
            ['email' => $customerData['email']],
            [
                'name' => $customerData['name'],
                'phone' => $customerData['phone'] ?? null,
                'address' => $customerData['address'] ?? null,
            ]
        )->id;
    }
}