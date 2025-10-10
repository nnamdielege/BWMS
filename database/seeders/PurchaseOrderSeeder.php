<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Models\Product;

class PurchaseOrderSeeder extends Seeder
{
    public function run(): void
    {
        $supplier = Supplier::first();
        $warehouse = Warehouse::first();
        $products = Product::take(3)->get();

        if (!$supplier || !$warehouse || $products->count() < 3) {
            $this->command->warn('Please seed suppliers, warehouses, and products first');
            return;
        }

        // Create purchase order 1
        $po1 = PurchaseOrder::create([
            'order_number' => 'PO-20251009-0001',
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'order_date' => now(),
            'expected_date' => now()->addDays(7),
            'status' => 'pending',
            'subtotal' => 500.00,
            'tax_rate' => 10.00,
            'tax' => 50.00,
            'shipping' => 20.00,
            'discount' => 0.00,
            'total' => 570.00,
            'notes' => 'Regular stock replenishment',
        ]);

        // Add items to PO1
        PurchaseOrderItem::create([
            'purchase_order_id' => $po1->id,
            'product_id' => $products[0]->id,
            'quantity' => 50,
            'received_quantity' => 0,
            'unit_price' => 5.00,
            'discount' => 0.00,
            'tax' => 0.00,
            'subtotal' => 250.00,
        ]);

        PurchaseOrderItem::create([
            'purchase_order_id' => $po1->id,
            'product_id' => $products[1]->id,
            'quantity' => 25,
            'received_quantity' => 0,
            'unit_price' => 10.00,
            'discount' => 0.00,
            'tax' => 0.00,
            'subtotal' => 250.00,
        ]);

        $this->command->info('Purchase orders seeded successfully');
    }
}