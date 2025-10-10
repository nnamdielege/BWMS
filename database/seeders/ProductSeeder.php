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
        $electronics = ProductCategory::where('slug', 'electronics')->first();
        $furniture = ProductCategory::where('slug', 'furniture')->first();
        $officeSupplies = ProductCategory::where('slug', 'office-supplies')->first();
        $foodBeverage = ProductCategory::where('slug', 'food-beverage')->first();

        // Get warehouses for inventory
        $warehouses = Warehouse::all();

        $products = [
            // Electronics
            [
                'sku' => 'ELEC-LAP-001',
                'name' => 'Dell Latitude 5420 Laptop',
                'description' => '14" FHD, Intel i5-1145G7, 16GB RAM, 256GB SSD',
                'category_id' => $electronics->id,
                'cost' => 650.00,
                'price' => 999.00,
                'unit_of_measure' => 'PCS',
                'reorder_point' => 5,
                'reorder_quantity' => 10,
                'weight' => 1.4,
                'dimensions' => '32.1 x 21.2 x 1.8 cm',
                'is_active' => true,
                'initial_stock' => 25,
            ],
            [
                'sku' => 'ELEC-MON-001',
                'name' => 'Dell 24" Monitor P2422H',
                'description' => '24-inch Full HD LED Monitor, 1920x1080',
                'category_id' => $electronics->id,
                'cost' => 120.00,
                'price' => 199.00,
                'unit_of_measure' => 'PCS',
                'reorder_point' => 10,
                'reorder_quantity' => 20,
                'weight' => 4.2,
                'dimensions' => '53.7 x 41.1 x 18.5 cm',
                'is_active' => true,
                'initial_stock' => 50,
            ],
            [
                'sku' => 'ELEC-KEY-001',
                'name' => 'Logitech K380 Wireless Keyboard',
                'description' => 'Multi-device Bluetooth keyboard',
                'category_id' => $electronics->id,
                'cost' => 25.00,
                'price' => 45.00,
                'unit_of_measure' => 'PCS',
                'reorder_point' => 15,
                'reorder_quantity' => 30,
                'weight' => 0.42,
                'dimensions' => '27.9 x 12.4 x 1.6 cm',
                'is_active' => true,
                'initial_stock' => 100,
            ],
            [
                'sku' => 'ELEC-MOU-001',
                'name' => 'Logitech M720 Wireless Mouse',
                'description' => 'Triathlon multi-device wireless mouse',
                'category_id' => $electronics->id,
                'cost' => 30.00,
                'price' => 55.00,
                'unit_of_measure' => 'PCS',
                'reorder_point' => 20,
                'reorder_quantity' => 40,
                'weight' => 0.14,
                'dimensions' => '11.5 x 7.4 x 4.5 cm',
                'is_active' => true,
                'initial_stock' => 80,
            ],
            [
                'sku' => 'ELEC-HDM-001',
                'name' => 'HDMI Cable 2m',
                'description' => 'High-speed HDMI 2.0 cable, 4K support',
                'category_id' => $electronics->id,
                'cost' => 5.00,
                'price' => 12.00,
                'unit_of_measure' => 'PCS',
                'reorder_point' => 30,
                'reorder_quantity' => 50,
                'weight' => 0.15,
                'dimensions' => '20 x 15 x 3 cm',
                'is_active' => true,
                'initial_stock' => 120,
            ],

            // Furniture
            [
                'sku' => 'FURN-DSK-001',
                'name' => 'Executive Office Desk',
                'description' => 'L-shaped executive desk with drawer, 160x140cm',
                'category_id' => $furniture->id,
                'cost' => 350.00,
                'price' => 599.00,
                'unit_of_measure' => 'PCS',
                'reorder_point' => 3,
                'reorder_quantity' => 5,
                'weight' => 45.0,
                'dimensions' => '160 x 140 x 75 cm',
                'is_active' => true,
                'initial_stock' => 15,
            ],
            [
                'sku' => 'FURN-CHR-001',
                'name' => 'Ergonomic Office Chair',
                'description' => 'High-back mesh office chair with lumbar support',
                'category_id' => $furniture->id,
                'cost' => 120.00,
                'price' => 249.00,
                'unit_of_measure' => 'PCS',
                'reorder_point' => 5,
                'reorder_quantity' => 10,
                'weight' => 18.0,
                'dimensions' => '65 x 65 x 115 cm',
                'is_active' => true,
                'initial_stock' => 30,
            ],
            [
                'sku' => 'FURN-CAB-001',
                'name' => 'Filing Cabinet 4-Drawer',
                'description' => 'Metal filing cabinet with lock, 4 drawers',
                'category_id' => $furniture->id,
                'cost' => 180.00,
                'price' => 329.00,
                'unit_of_measure' => 'PCS',
                'reorder_point' => 3,
                'reorder_quantity' => 5,
                'weight' => 35.0,
                'dimensions' => '45 x 60 x 132 cm',
                'is_active' => true,
                'initial_stock' => 12,
            ],
            [
                'sku' => 'FURN-SHF-001',
                'name' => 'Bookshelf 5-Tier',
                'description' => 'Wooden bookshelf, 5 shelves, adjustable',
                'category_id' => $furniture->id,
                'cost' => 80.00,
                'price' => 149.00,
                'unit_of_measure' => 'PCS',
                'reorder_point' => 5,
                'reorder_quantity' => 10,
                'weight' => 25.0,
                'dimensions' => '80 x 30 x 180 cm',
                'is_active' => true,
                'initial_stock' => 20,
            ],

            // Office Supplies
            [
                'sku' => 'OFF-PEN-001',
                'name' => 'Ballpoint Pens Blue (Box of 50)',
                'description' => 'Premium ballpoint pens, blue ink',
                'category_id' => $officeSupplies->id,
                'cost' => 8.00,
                'price' => 15.00,
                'unit_of_measure' => 'BOX',
                'reorder_point' => 50,
                'reorder_quantity' => 100,
                'weight' => 0.5,
                'dimensions' => '20 x 15 x 5 cm',
                'is_active' => true,
                'initial_stock' => 200,
            ],
            [
                'sku' => 'OFF-PAP-001',
                'name' => 'A4 Copy Paper (Ream)',
                'description' => 'White A4 copy paper, 500 sheets, 80gsm',
                'category_id' => $officeSupplies->id,
                'cost' => 3.50,
                'price' => 6.99,
                'unit_of_measure' => 'REAM',
                'reorder_point' => 100,
                'reorder_quantity' => 200,
                'weight' => 2.5,
                'dimensions' => '30 x 21 x 5 cm',
                'is_active' => true,
                'initial_stock' => 500,
            ],
            [
                'sku' => 'OFF-STA-001',
                'name' => 'Stapler Heavy Duty',
                'description' => 'Heavy duty stapler, 100 sheet capacity',
                'category_id' => $officeSupplies->id,
                'cost' => 12.00,
                'price' => 22.00,
                'unit_of_measure' => 'PCS',
                'reorder_point' => 20,
                'reorder_quantity' => 30,
                'weight' => 0.8,
                'dimensions' => '20 x 8 x 5 cm',
                'is_active' => true,
                'initial_stock' => 60,
            ],
            [
                'sku' => 'OFF-FOL-001',
                'name' => 'File Folders (Pack of 25)',
                'description' => 'Manila file folders, legal size',
                'category_id' => $officeSupplies->id,
                'cost' => 5.00,
                'price' => 10.00,
                'unit_of_measure' => 'PACK',
                'reorder_point' => 30,
                'reorder_quantity' => 50,
                'weight' => 1.2,
                'dimensions' => '35 x 25 x 2 cm',
                'is_active' => true,
                'initial_stock' => 150,
            ],
            [
                'sku' => 'OFF-NOT-001',
                'name' => 'Sticky Notes 3x3 (Pack of 12)',
                'description' => 'Yellow sticky notes, 100 sheets per pad',
                'category_id' => $officeSupplies->id,
                'cost' => 4.00,
                'price' => 8.50,
                'unit_of_measure' => 'PACK',
                'reorder_point' => 40,
                'reorder_quantity' => 80,
                'weight' => 0.4,
                'dimensions' => '12 x 10 x 8 cm',
                'is_active' => true,
                'initial_stock' => 180,
            ],

            // food Beverage
            [
                'sku' => 'CONS-TON-001',
                'name' => 'HP LaserJet Toner CF283A',
                'description' => 'Black toner cartridge for HP LaserJet Pro',
                'category_id' => $foodBeverage->id,
                'cost' => 35.00,
                'price' => 65.00,
                'unit_of_measure' => 'PCS',
                'reorder_point' => 20,
                'reorder_quantity' => 30,
                'weight' => 0.6,
                'dimensions' => '15 x 10 x 10 cm',
                'is_active' => true,
                'initial_stock' => 50,
            ],
            [
                'sku' => 'CONS-BAT-001',
                'name' => 'AA Batteries (Pack of 24)',
                'description' => 'Alkaline AA batteries, long-lasting',
                'category_id' => $foodBeverage->id,
                'cost' => 8.00,
                'price' => 15.00,
                'unit_of_measure' => 'PACK',
                'reorder_point' => 40,
                'reorder_quantity' => 80,
                'weight' => 0.5,
                'dimensions' => '12 x 10 x 5 cm',
                'is_active' => true,
                'initial_stock' => 100,
            ],
            [
                'sku' => 'CONS-CLN-001',
                'name' => 'Screen Cleaning Kit',
                'description' => 'Screen cleaner spray and microfiber cloth',
                'category_id' => $foodBeverage->id,
                'cost' => 5.00,
                'price' => 12.00,
                'unit_of_measure' => 'KIT',
                'reorder_point' => 25,
                'reorder_quantity' => 50,
                'weight' => 0.3,
                'dimensions' => '15 x 8 x 5 cm',
                'is_active' => true,
                'initial_stock' => 80,
            ],
            [
                'sku' => 'CONS-INK-001',
                'name' => 'Canon Ink Cartridge PG-245XL',
                'description' => 'Black ink cartridge, high yield',
                'category_id' => $foodBeverage->id,
                'cost' => 25.00,
                'price' => 45.00,
                'unit_of_measure' => 'PCS',
                'reorder_point' => 30,
                'reorder_quantity' => 50,
                'weight' => 0.08,
                'dimensions' => '10 x 8 x 5 cm',
                'is_active' => true,
                'initial_stock' => 75,
            ],
        ];

        foreach ($products as $productData) {
            $initialStock = $productData['initial_stock'] ?? 0;
            unset($productData['initial_stock']);

            // Create product
            $product = Product::create($productData);

            // Create inventory for each warehouse if initial stock is provided
            if ($initialStock > 0 && $warehouses->count() > 0) {
                // Distribute stock across warehouses
                $stockPerWarehouse = floor($initialStock / $warehouses->count());
                $remainder = $initialStock % $warehouses->count();

                foreach ($warehouses as $index => $warehouse) {
                    $quantity = $stockPerWarehouse;

                    // Add remainder to first warehouse
                    if ($index === 0) {
                        $quantity += $remainder;
                    }

                    if ($quantity > 0) {
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
        }

        $this->command->info('Created ' . count($products) . ' products with inventory!');
    }
}