<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Inventory;
use App\Models\InventoryTransaction;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InventoryService
{
    protected $pushService;

    public function __construct(InventoryPushService $pushService = null)
    {
        $this->pushService = $pushService;
    }

    /**
     * Adjust stock with optional auto-push to Ordermentum
     */
    public function adjustStock(array $data)
    {
        return DB::transaction(function () use ($data) {
            $inventory = Inventory::where('product_id', $data['product_id'])
                ->where('warehouse_id', $data['warehouse_id'])
                ->firstOrFail();

            $oldQuantity = $inventory->quantity_on_hand;
            $adjustment = $data['quantity'];
            $newQuantity = $oldQuantity + $adjustment;

            // Update inventory
            $inventory->update([
                'quantity_on_hand' => $newQuantity,
                'quantity_available' => $newQuantity - $inventory->quantity_allocated,
            ]);

            // Record transaction
            InventoryTransaction::create([
                'product_id' => $data['product_id'],
                'warehouse_id' => $data['warehouse_id'],
                'type' => $adjustment > 0 ? 'in' : 'out',
                'quantity' => abs($adjustment),
                'quantity_before' => $oldQuantity,
                'quantity_after' => $newQuantity,
                'reason' => $data['reason'] ?? 'manual_adjustment',
                'reference' => $data['reference'] ?? null,
                'user_id' => auth()->id(),
            ]);

            // ============================================
            // AUTO-PUSH TO ORDERMENTUM (NEW)
            // ============================================
            $pushResult = null;

            // Check if auto-push is enabled (default: true)
            $shouldPush = $data['push_to_ordermentum'] ?? true;

            if ($shouldPush && $this->pushService) {
                try {
                    $pushSuccessful = $this->pushService->pushToOrdermentum($inventory);

                    $pushResult = [
                        'attempted' => true,
                        'successful' => $pushSuccessful,
                        'error' => $pushSuccessful ? null : 'Failed to push to Ordermentum',
                    ];

                    if ($pushSuccessful) {
                        Log::info('Auto-push to Ordermentum successful', [
                            'inventory_id' => $inventory->id,
                            'product_id' => $data['product_id'],
                            'warehouse_id' => $data['warehouse_id'],
                            'quantity' => $newQuantity,
                        ]);
                    } else {
                        Log::warning('Auto-push to Ordermentum failed', [
                            'inventory_id' => $inventory->id,
                            'product_id' => $data['product_id'],
                            'reason' => 'Push service returned false',
                        ]);
                    }
                } catch (\Exception $e) {
                    $pushResult = [
                        'attempted' => true,
                        'successful' => false,
                        'error' => $e->getMessage(),
                    ];

                    Log::error('Auto-push to Ordermentum error: ' . $e->getMessage(), [
                        'inventory_id' => $inventory->id,
                        'product_id' => $data['product_id'],
                    ]);
                }
            }
            // ============================================

            $inventoryFresh = $inventory->fresh();
            $inventoryFresh->ordermentum_push = $pushResult;

            return $inventoryFresh;
        });
    }

    /**
     * Transfer stock between warehouses with optional auto-push to Ordermentum
     */
    public function transferStock(array $data)
    {
        return DB::transaction(function () use ($data) {
            // Reduce from source warehouse
            $sourceInventory = Inventory::where('product_id', $data['product_id'])
                ->where('warehouse_id', $data['from_warehouse_id'])
                ->lockForUpdate()
                ->firstOrFail();

            if ($sourceInventory->quantity_available < $data['quantity']) {
                throw new \Exception('Insufficient stock in source warehouse');
            }

            $sourceOldQuantity = $sourceInventory->quantity_on_hand;
            $sourceInventory->decrement('quantity_on_hand', $data['quantity']);
            $sourceInventory->decrement('quantity_available', $data['quantity']);

            // Increase in destination warehouse
            $destInventory = Inventory::where('product_id', $data['product_id'])
                ->where('warehouse_id', $data['to_warehouse_id'])
                ->lockForUpdate()
                ->firstOrFail();

            $destOldQuantity = $destInventory->quantity_on_hand;
            $destInventory->increment('quantity_on_hand', $data['quantity']);
            $destInventory->increment('quantity_available', $data['quantity']);

            // Record transactions
            InventoryTransaction::create([
                'product_id' => $data['product_id'],
                'warehouse_id' => $data['from_warehouse_id'],
                'type' => 'out',
                'quantity' => $data['quantity'],
                'quantity_before' => $sourceOldQuantity,
                'quantity_after' => $sourceInventory->quantity_on_hand,
                'reason' => 'transfer',
                'reference' => "Transfer to Warehouse #{$data['to_warehouse_id']}",
                'user_id' => auth()->id(),
            ]);

            InventoryTransaction::create([
                'product_id' => $data['product_id'],
                'warehouse_id' => $data['to_warehouse_id'],
                'type' => 'in',
                'quantity' => $data['quantity'],
                'quantity_before' => $destOldQuantity,
                'quantity_after' => $destInventory->quantity_on_hand,
                'reason' => 'transfer',
                'reference' => "Transfer from Warehouse #{$data['from_warehouse_id']}",
                'user_id' => auth()->id(),
            ]);

            // ============================================
            // AUTO-PUSH TO ORDERMENTUM (NEW)
            // Push from destination warehouse (the one receiving stock)
            // ============================================
            $pushResult = null;

            // Check if auto-push is enabled (default: true)
            $shouldPush = $data['push_to_ordermentum'] ?? true;

            if ($shouldPush && $this->pushService) {
                try {
                    // Push from destination warehouse
                    $pushSuccessful = $this->pushService->pushToOrdermentum($destInventory);

                    $pushResult = [
                        'attempted' => true,
                        'successful' => $pushSuccessful,
                        'error' => $pushSuccessful ? null : 'Failed to push to Ordermentum',
                        'pushed_from_warehouse' => $destInventory->warehouse->name ?? "Warehouse #{$data['to_warehouse_id']}",
                    ];

                    if ($pushSuccessful) {
                        Log::info('Auto-push to Ordermentum successful after transfer', [
                            'source_warehouse_id' => $data['from_warehouse_id'],
                            'dest_warehouse_id' => $data['to_warehouse_id'],
                            'inventory_id' => $destInventory->id,
                            'product_id' => $data['product_id'],
                            'quantity' => $destInventory->quantity_on_hand,
                        ]);
                    } else {
                        Log::warning('Auto-push to Ordermentum failed after transfer', [
                            'inventory_id' => $destInventory->id,
                            'product_id' => $data['product_id'],
                        ]);
                    }
                } catch (\Exception $e) {
                    $pushResult = [
                        'attempted' => true,
                        'successful' => false,
                        'error' => $e->getMessage(),
                        'pushed_from_warehouse' => $destInventory->warehouse->name ?? "Warehouse #{$data['to_warehouse_id']}",
                    ];

                    Log::error('Auto-push to Ordermentum error after transfer: ' . $e->getMessage(), [
                        'inventory_id' => $destInventory->id,
                        'product_id' => $data['product_id'],
                    ]);
                }
            }
            // ============================================

            $sourceInventoryFresh = $sourceInventory->fresh();
            $destInventoryFresh = $destInventory->fresh();

            return [
                'source' => $sourceInventoryFresh,
                'destination' => $destInventoryFresh,
                'ordermentum_push' => $pushResult,
            ];
        });
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
