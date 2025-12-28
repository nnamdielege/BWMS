<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\Inventory;
use App\Models\Warehouse;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncFromOrdermentumStock extends Command
{
    protected $signature = 'sync:stock {--warehouse=} {--force} {--dry-run}';
    protected $description = 'Sync stock levels between Ordermentum API and Inventory table';

    private $accessToken = null;
    private $baseUrl = 'https://app.ordermentum.com/v1';
    private $dryRun = false;
    private $syncLog = [];

    public function handle()
    {
        $this->dryRun = $this->option('dry-run');
        $force = $this->option('force');
        $warehouseId = $this->option('warehouse');

        if ($this->dryRun) {
            $this->warn('⚠ DRY RUN MODE - No changes will be saved');
        }

        // Get warehouse (default to first one if not specified)
        $warehouse = null;
        if ($warehouseId) {
            $warehouse = Warehouse::find($warehouseId);
            if (!$warehouse) {
                $this->error("Warehouse not found: {$warehouseId}");
                return;
            }
            $this->info("Syncing to warehouse: {$warehouse->name}");
        } else {
            $warehouse = Warehouse::first();
            if (!$warehouse) {
                $this->error('No warehouses found');
                return;
            }
            $this->info("Syncing to default warehouse: {$warehouse->name}");
        }

        // Step 1: Authenticate
        $this->info('\nStep 1: Authenticating with Ordermentum...');
        if (!$this->authenticate()) {
            $this->error('Failed to authenticate');
            return;
        }
        $this->info('✓ Authenticated successfully');

        // Step 2: Get all products with variant IDs
        $this->info("\nStep 2: Fetching products with Ordermentum IDs...");
        $products = Product::whereNotNull('ordermentum_variant_id')
            ->whereNotNull('ordermentum_product_id')
            ->get();

        $this->info("Found {$products->count()} products to sync");

        if ($products->isEmpty()) {
            $this->warn('No products with Ordermentum IDs found');
            return;
        }

        // Step 3: Fetch stock from Ordermentum
        $this->info("\nStep 3: Fetching stock levels from Ordermentum...");
        $bar = $this->output->createProgressBar($products->count());
        $bar->start();

        $ordermentumStock = [];
        $syncErrors = [];

        foreach ($products as $product) {
            try {
                $stock = $this->fetchStockFromOrdermentum(
                    $product->ordermentum_product_id,
                    $product->ordermentum_variant_id
                );

                if ($stock !== null) {
                    $ordermentumStock[$product->id] = [
                        'product_id' => $product->ordermentum_product_id,
                        'variant_id' => $product->ordermentum_variant_id,
                        'quantity' => $stock,
                        'sku' => $product->sku,
                        'name' => $product->name,
                    ];
                }
            } catch (\Exception $e) {
                $syncErrors[] = "Product {$product->sku}: " . $e->getMessage();
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("✓ Fetched " . count($ordermentumStock) . " stock levels");

        // Step 4: Compare and update
        $this->info("\nStep 4: Comparing and updating stock...");
        $updates = $this->compareAndPrepareUpdates($products, $ordermentumStock, $warehouse);

        $this->displayUpdateSummary($updates);

        // Step 5: Apply updates
        if (!empty($updates['create']) || !empty($updates['update'])) {
            if ($this->dryRun) {
                $this->warn('\n[DRY RUN] Updates would be applied but no changes saved');
            } else {
                $this->info("\nStep 5: Applying updates to inventory...");
                $this->applyUpdates($updates, $warehouse);
                $this->info('✓ Updates applied successfully');
            }
        }

        // Step 6: Log summary
        $this->logSyncSummary($updates, $syncErrors, $warehouse);

        // Display results
        $this->newLine();
        $this->info('=== SYNC SUMMARY ===');
        $this->line("Created: " . count($updates['create']));
        $this->line("Updated: " . count($updates['update']));
        $this->line("Synced: " . count($updates['synced']));
        $this->line("Errors: " . count($syncErrors));

        if (!empty($syncErrors)) {
            $this->warn('\nErrors encountered:');
            foreach (array_slice($syncErrors, 0, 5) as $error) {
                $this->line("  ⚠ {$error}");
            }
            if (count($syncErrors) > 5) {
                $this->line("  ... and " . (count($syncErrors) - 5) . " more");
            }
        }
    }

    /**
     * Authenticate with Ordermentum API
     */
    private function authenticate()
    {
        $username = env('ORDERMENTUM_USERNAME');
        $password = env('ORDERMENTUM_PASSWORD');

        if (!$username || !$password) {
            $this->error('ORDERMENTUM_USERNAME and ORDERMENTUM_PASSWORD not set');
            return false;
        }

        $url = $this->baseUrl . '/auth';

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['username' => $username, 'password' => $password]));
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 201) {
            $data = json_decode($response, true);
            if (isset($data['access_token'])) {
                $this->accessToken = $data['access_token'];
                return true;
            }
        }

        return false;
    }

    /**
     * Fetch stock from Ordermentum for a specific variant
     */
    private function fetchStockFromOrdermentum($productId, $variantId)
    {
        if (!$productId || !$variantId) {
            return null;
        }

        $url = $this->baseUrl . "/products/{$productId}/variants/{$variantId}";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->accessToken,
            'Content-Type: application/json',
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            $data = json_decode($response, true);

            // Handle different response formats
            $variant = isset($data['variant']) ? $data['variant'] : $data;

            // Try to get available quantity
            if (isset($variant['available'])) {
                return (int)$variant['available'];
            }
            if (isset($variant['quantity'])) {
                return (int)$variant['quantity'];
            }
            if (isset($variant['stock'])) {
                return (int)$variant['stock'];
            }

            // Check availability status
            if (isset($variant['outOfStock']) && $variant['outOfStock'] === false) {
                return 1; // Available but quantity unknown
            }
        }

        return null;
    }

    /**
     * Compare Ordermentum stock with Inventory table and prepare updates
     */
    private function compareAndPrepareUpdates($products, $ordermentumStock, $warehouse)
    {
        $updates = [
            'create' => [],
            'update' => [],
            'synced' => [],
            'unchanged' => [],
        ];

        foreach ($products as $product) {
            if (!isset($ordermentumStock[$product->id])) {
                // No stock data from Ordermentum - skip
                continue;
            }

            // Get or create inventory record for this product/warehouse
            $inventory = Inventory::firstOrCreate(
                [
                    'product_id' => $product->id,
                    'warehouse_id' => $warehouse->id,
                ],
                [
                    'quantity_on_hand' => 0,
                    'quantity_available' => 0,
                    'quantity_allocated' => 0,
                    'quantity_on_order' => 0,
                ]
            );

            $localQuantity = $inventory->quantity_on_hand ?? 0;
            $remoteQuantity = $ordermentumStock[$product->id]['quantity'];
            $sku = $product->sku;
            $name = $product->name;

            // Log the comparison
            Log::info("Stock sync comparison", [
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'sku' => $sku,
                'local' => $localQuantity,
                'remote' => $remoteQuantity,
                'action' => $localQuantity !== $remoteQuantity ? 'UPDATE' : 'NO_CHANGE',
            ]);

            if ($localQuantity === $remoteQuantity) {
                // No change needed
                $updates['unchanged'][] = [
                    'sku' => $sku,
                    'quantity' => $localQuantity,
                ];
            } else {
                // Update needed
                if ($inventory->wasRecentlyCreated) {
                    $updates['create'][] = [
                        'inventory_id' => $inventory->id,
                        'product_id' => $product->id,
                        'warehouse_id' => $warehouse->id,
                        'sku' => $sku,
                        'name' => $name,
                        'quantity' => $remoteQuantity,
                    ];
                } else {
                    $updates['update'][] = [
                        'inventory_id' => $inventory->id,
                        'product_id' => $product->id,
                        'sku' => $sku,
                        'name' => $name,
                        'old_quantity' => $localQuantity,
                        'new_quantity' => $remoteQuantity,
                        'difference' => $remoteQuantity - $localQuantity,
                    ];
                }

                $updates['synced'][] = $product->id;
            }
        }

        return $updates;
    }

    /**
     * Display update summary table
     */
    private function displayUpdateSummary($updates)
    {
        if (!empty($updates['update'])) {
            $this->newLine();
            $this->info('Stock updates required:');
            $this->line(str_repeat('-', 100));
            $this->line(sprintf(
                '%-15s %-50s %-12s %-12s %-12s',
                'SKU',
                'Name',
                'Old Qty',
                'New Qty',
                'Difference'
            ));
            $this->line(str_repeat('-', 100));

            foreach ($updates['update'] as $update) {
                $name = substr($update['name'], 0, 48);
                $this->line(sprintf(
                    '%-15s %-50s %-12d %-12d %-12d',
                    $update['sku'],
                    $name,
                    $update['old_quantity'],
                    $update['new_quantity'],
                    $update['difference']
                ));
            }

            $this->line(str_repeat('-', 100));
        }

        if (!empty($updates['unchanged'])) {
            $this->newLine();
            $this->info("Stock unchanged: " . count($updates['unchanged']) . " products");
        }
    }

    /**
     * Apply updates to Inventory table
     */
    private function applyUpdates($updates, $warehouse)
    {
        try {
            DB::beginTransaction();

            // Create new inventory records
            foreach ($updates['create'] as $create) {
                Inventory::updateOrCreate(
                    [
                        'product_id' => $create['product_id'],
                        'warehouse_id' => $create['warehouse_id'],
                    ],
                    [
                        'quantity_on_hand' => $create['quantity'],
                        'quantity_available' => $create['quantity'],
                    ]
                );

                Log::info('Inventory created', [
                    'product_id' => $create['product_id'],
                    'warehouse_id' => $create['warehouse_id'],
                    'sku' => $create['sku'],
                    'quantity' => $create['quantity'],
                ]);
            }

            // Update existing inventory records
            foreach ($updates['update'] as $update) {
                Inventory::where('id', $update['inventory_id'])->update([
                    'quantity_on_hand' => $update['new_quantity'],
                    'quantity_available' => $update['new_quantity'],
                    'updated_at' => now(),
                ]);

                Log::info('Inventory updated', [
                    'inventory_id' => $update['inventory_id'],
                    'product_id' => $update['product_id'],
                    'sku' => $update['sku'],
                    'old_quantity' => $update['old_quantity'],
                    'new_quantity' => $update['new_quantity'],
                    'warehouse_id' => $warehouse->id,
                ]);
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error('Error applying updates: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Log sync summary to file
     */
    private function logSyncSummary($updates, $errors, $warehouse)
    {
        $summary = [
            'timestamp' => now()->toIso8601String(),
            'warehouse_id' => $warehouse->id,
            'warehouse_name' => $warehouse->name,
            'created' => count($updates['create']),
            'updated' => count($updates['update']),
            'unchanged' => count($updates['unchanged']),
            'errors' => count($errors),
            'dry_run' => $this->dryRun,
        ];

        Log::info('Stock sync completed', $summary);
    }
}
