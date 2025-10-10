<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Warehouse;
use App\Models\Inventory;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        // Get categories
        $coffeeBeans = ProductCategory::where('slug', 'coffee-beans')->first();
        $syrups = ProductCategory::where('slug', 'syrups-flavours')->first();
        $drinkingChocolate = ProductCategory::where('slug', 'drinking-chocolate')->first();
        $chai = ProductCategory::where('slug', 'chai-latte-powders')->first();
        $tea = ProductCategory::where('slug', 'tea-infusions')->first();
        $equipment = ProductCategory::where('slug', 'equipment-accessories')->first();
        $essentials = ProductCategory::where('slug', 'cafe-essentials')->first();
        $bulk = ProductCategory::where('slug', 'bulk-beverages')->first();
        $merch = ProductCategory::where('slug', 'merchandise')->first();
        $wholesale = ProductCategory::where('slug', 'wholesale-supplies')->first();

        // Warehouses
        $warehouses = Warehouse::all();

        $products = [
            // ☕ Coffee Beans
            [
                'sku' => 'COF-BEAN-001',
                'name' => 'Signature Espresso Blend 1kg',
                'description' => 'Rich, smooth espresso blend roasted locally for cafes.',
                'category_id' => $coffeeBeans->id,
                'cost' => 22.00,
                'price' => 38.00,
                'unit_of_measure' => 'BAG',
                'reorder_point' => 15,
                'reorder_quantity' => 30,
                'weight' => 1.0,
                'dimensions' => '20 x 12 x 8 cm',
                'is_active' => true,
                'initial_stock' => 100,
            ],
            [
                'sku' => 'COF-BEAN-002',
                'name' => 'Single Origin Colombia 1kg',
                'description' => '100% Arabica coffee beans with fruity notes and balanced acidity.',
                'category_id' => $coffeeBeans->id,
                'cost' => 24.00,
                'price' => 42.00,
                'unit_of_measure' => 'BAG',
                'reorder_point' => 10,
                'reorder_quantity' => 20,
                'weight' => 1.0,
                'dimensions' => '20 x 12 x 8 cm',
                'is_active' => true,
                'initial_stock' => 80,
            ],

            // 🍯 Syrups & Flavours
            [
                'sku' => 'SYR-VAN-001',
                'name' => 'Vanilla Syrup 1L',
                'description' => 'Classic vanilla syrup for coffee, milkshakes and desserts.',
                'category_id' => $syrups->id,
                'cost' => 9.00,
                'price' => 16.00,
                'unit_of_measure' => 'BOTTLE',
                'reorder_point' => 20,
                'reorder_quantity' => 40,
                'weight' => 1.2,
                'dimensions' => '30 x 9 x 9 cm',
                'is_active' => true,
                'initial_stock' => 150,
            ],
            [
                'sku' => 'SYR-CRM-001',
                'name' => 'Caramel Syrup 1L',
                'description' => 'Smooth caramel syrup ideal for hot and cold beverages.',
                'category_id' => $syrups->id,
                'cost' => 9.00,
                'price' => 16.00,
                'unit_of_measure' => 'BOTTLE',
                'reorder_point' => 20,
                'reorder_quantity' => 40,
                'weight' => 1.2,
                'dimensions' => '30 x 9 x 9 cm',
                'is_active' => true,
                'initial_stock' => 150,
            ],

            // 🍫 Drinking Chocolate
            [
                'sku' => 'DRC-CHC-001',
                'name' => 'Premium Drinking Chocolate 1kg',
                'description' => 'Rich cocoa blend perfect for hot chocolates and mochas.',
                'category_id' => $drinkingChocolate->id,
                'cost' => 12.00,
                'price' => 22.00,
                'unit_of_measure' => 'BAG',
                'reorder_point' => 10,
                'reorder_quantity' => 25,
                'weight' => 1.0,
                'dimensions' => '20 x 12 x 8 cm',
                'is_active' => true,
                'initial_stock' => 90,
            ],

            // 🍵 Chai Latte Powders
            [
                'sku' => 'CHAI-ORG-001',
                'name' => 'Organic Chai Latte 1kg',
                'description' => 'Spiced blend of black tea, cinnamon, cardamom, and cloves.',
                'category_id' => $chai->id,
                'cost' => 15.00,
                'price' => 28.00,
                'unit_of_measure' => 'BAG',
                'reorder_point' => 10,
                'reorder_quantity' => 25,
                'weight' => 1.0,
                'dimensions' => '20 x 12 x 8 cm',
                'is_active' => true,
                'initial_stock' => 80,
            ],

            // 🍃 Tea & Infusions
            [
                'sku' => 'TEA-GRN-001',
                'name' => 'Green Tea Loose Leaf 500g',
                'description' => 'Premium green tea with delicate aroma and fresh taste.',
                'category_id' => $tea->id,
                'cost' => 10.00,
                'price' => 18.00,
                'unit_of_measure' => 'BAG',
                'reorder_point' => 15,
                'reorder_quantity' => 30,
                'weight' => 0.5,
                'dimensions' => '18 x 10 x 6 cm',
                'is_active' => true,
                'initial_stock' => 70,
            ],

            // ⚙️ Equipment & Accessories
            [
                'sku' => 'EQP-TAMP-001',
                'name' => 'Coffee Tamper 58mm',
                'description' => 'Professional stainless-steel coffee tamper for espresso machines.',
                'category_id' => $equipment->id,
                'cost' => 18.00,
                'price' => 35.00,
                'unit_of_measure' => 'PCS',
                'reorder_point' => 5,
                'reorder_quantity' => 10,
                'weight' => 0.4,
                'dimensions' => '7 x 7 x 10 cm',
                'is_active' => true,
                'initial_stock' => 25,
            ],

            // 🧺 Cafe Essentials
            [
                'sku' => 'ESS-CUP-001',
                'name' => 'Takeaway Coffee Cups 12oz (Carton of 1000)',
                'description' => 'Biodegradable takeaway coffee cups for cafes.',
                'category_id' => $essentials->id,
                'cost' => 55.00,
                'price' => 95.00,
                'unit_of_measure' => 'CARTON',
                'reorder_point' => 5,
                'reorder_quantity' => 10,
                'weight' => 8.0,
                'dimensions' => '60 x 40 x 50 cm',
                'is_active' => true,
                'initial_stock' => 10,
            ],

            // 🧃 Bulk Beverages
            [
                'sku' => 'BLK-MLK-001',
                'name' => 'Almond Milk Barista 1L (Carton of 8)',
                'description' => 'Plant-based milk designed for perfect frothing and steaming.',
                'category_id' => $bulk->id,
                'cost' => 18.00,
                'price' => 32.00,
                'unit_of_measure' => 'CARTON',
                'reorder_point' => 10,
                'reorder_quantity' => 20,
                'weight' => 8.0,
                'dimensions' => '40 x 25 x 25 cm',
                'is_active' => true,
                'initial_stock' => 40,
            ],

            // 🎁 Merchandise
            [
                'sku' => 'MER-MUG-001',
                'name' => 'Local Selection Coffee Mug',
                'description' => 'Branded ceramic mug with Local Selection logo.',
                'category_id' => $merch->id,
                'cost' => 6.00,
                'price' => 15.00,
                'unit_of_measure' => 'PCS',
                'reorder_point' => 10,
                'reorder_quantity' => 20,
                'weight' => 0.35,
                'dimensions' => '10 x 10 x 10 cm',
                'is_active' => true,
                'initial_stock' => 50,
            ],

            // 📦 Wholesale Supplies
            [
                'sku' => 'WHL-SUG-001',
                'name' => 'White Sugar 25kg Bag',
                'description' => 'Premium white sugar for cafes and bakeries.',
                'category_id' => $wholesale->id,
                'cost' => 35.00,
                'price' => 58.00,
                'unit_of_measure' => 'BAG',
                'reorder_point' => 3,
                'reorder_quantity' => 6,
                'weight' => 25.0,
                'dimensions' => '60 x 40 x 20 cm',
                'is_active' => true,
                'initial_stock' => 15,
            ],
        ];

        // Create products and inventory
        foreach ($products as $productData) {
            $initialStock = $productData['initial_stock'] ?? 0;
            unset($productData['initial_stock']);

            $product = Product::create($productData);

            if ($initialStock > 0 && $warehouses->count() > 0) {
                $stockPerWarehouse = floor($initialStock / $warehouses->count());
                $remainder = $initialStock % $warehouses->count();

                foreach ($warehouses as $index => $warehouse) {
                    $quantity = $stockPerWarehouse;
                    if ($index === 0) {
                        $quantity += $remainder;
                    }

                    Inventory::create([
                        'product_id' => $product->id,
                        'warehouse_id' => $warehouse->id,
                        'quantity_on_hand' => $quantity,
                        'quantity_available' => $quantity,
                        'quantity_allocated' => 0,
                        'quantity_on_order' => 0,
                    ]);
                }
            }
        }

        $this->command->info('Created ' . count($products) . ' Local Selection products with inventory!');
    }
}