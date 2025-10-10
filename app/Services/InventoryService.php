<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Inventory;
use App\Models\InventoryTransaction;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;

class InventoryService
{
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

            return $inventory->fresh();
        });
    }

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

            $sourceInventory->decrement('quantity_on_hand', $data['quantity']);
            $sourceInventory->decrement('quantity_available', $data['quantity']);

            // Increase in destination warehouse
            $destInventory = Inventory::where('product_id', $data['product_id'])
                ->where('warehouse_id', $data['to_warehouse_id'])
                ->lockForUpdate()
                ->firstOrFail();

            $destInventory->increment('quantity_on_hand', $data['quantity']);
            $destInventory->increment('quantity_available', $data['quantity']);

            // Record transactions
            InventoryTransaction::create([
                'product_id' => $data['product_id'],
                'warehouse_id' => $data['from_warehouse_id'],
                'type' => 'out',
                'quantity' => $data['quantity'],
                'reason' => 'transfer',
                'reference' => "Transfer to Warehouse #{$data['to_warehouse_id']}",
                'user_id' => auth()->id(),
            ]);

            InventoryTransaction::create([
                'product_id' => $data['product_id'],
                'warehouse_id' => $data['to_warehouse_id'],
                'type' => 'in',
                'quantity' => $data['quantity'],
                'reason' => 'transfer',
                'reference' => "Transfer from Warehouse #{$data['from_warehouse_id']}",
                'user_id' => auth()->id(),
            ]);

            return [
                'source' => $sourceInventory->fresh(),
                'destination' => $destInventory->fresh(),
            ];
        });
    }
}