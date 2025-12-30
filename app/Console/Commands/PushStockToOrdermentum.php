<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\Inventory;
use App\Models\Warehouse;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PushStockToOrdermentum extends Command
{
    protected $signature = 'push:stock {--warehouse=} {--product=} {--force} {--dry-run}';
    protected $description = 'Push stock levels from Inventory table to Ordermentum API';

    private $accessToken = null;
    private $baseUrl = 'https://app.ordermentum.com/v1';
    private $stockUrl = 'https://stock.ordermentum.com/v1';
    private $dryRun = false;
    private $pushed = 0;
    private $failed = 0;

    public function handle()
    {
        $this->dryRun = $this->option('dry-run');
        $warehouseId = $this->option('warehouse');
        $productId = $this->option('product');

        if ($this->dryRun) {
            $this->warn('⚠ DRY RUN MODE - No changes will be sent to Ordermentum');
        }

        // Get warehouse (optional filter)
        $warehouse = null;
        if ($warehouseId) {
            $warehouse = Warehouse::find($warehouseId);
            if (!$warehouse) {
                $this->error("Warehouse not found: {$warehouseId}");
                return;
            }
            $this->info("Pushing from warehouse: {$warehouse->name}");
        } else {
            $warehouse = Warehouse::first();
            if (!$warehouse) {
                $this->error('No warehouses found');
                return;
            }
            $this->info("Using default warehouse: {$warehouse->name}");
        }

        // Step 1: Authenticate
        $this->info("\nStep 1: Authenticating with Ordermentum...");
        if (!$this->authenticate()) {
            $this->error('Failed to authenticate');
            return;
        }
        $this->info('✓ Authenticated successfully');

        // Step 2: Get inventory stock levels to push
        $this->info("\nStep 2: Fetching inventory stock levels...");
        $query = Inventory::where('warehouse_id', $warehouse->id)
            ->whereHas('product', function ($q) {
                $q->whereNotNull('ordermentum_variant_id');
            });

        if ($productId) {
            $query->where('product_id', $productId);
        }

        $inventory = $query->with('product')->get();

        $this->info("Found {$inventory->count()} stock levels to push");

        if ($inventory->isEmpty()) {
            $this->warn('No inventory records with Ordermentum variant IDs found');
            return;
        }

        // Step 3: Push stock levels to Ordermentum
        $this->info("\nStep 3: Pushing stock levels to Ordermentum...");
        $bar = $this->output->createProgressBar($inventory->count());
        $bar->start();

        $pushUpdates = [];
        $pushErrors = [];

        foreach ($inventory as $inv) {
            try {
                $quantity = $inv->quantity_on_hand ?? 0;

                if ($this->dryRun) {
                    // Dry run - pretend success
                    $pushUpdates[] = [
                        'inventory_id' => $inv->id,
                        'product_id' => $inv->product_id,
                        'warehouse_id' => $inv->warehouse_id,
                        'sku' => $inv->product->sku,
                        'name' => $inv->product->name,
                        'quantity' => $quantity,
                        'status' => 'dry-run',
                        'timestamp' => now(),
                    ];
                    $this->pushed++;
                } else {
                    $result = $this->pushStockToOrdermentum(
                        $inv->product->ordermentum_variant_id,
                        $quantity,
                        $inv->product
                    );

                    if ($result) {
                        $pushUpdates[] = [
                            'inventory_id' => $inv->id,
                            'product_id' => $inv->product_id,
                            'warehouse_id' => $inv->warehouse_id,
                            'sku' => $inv->product->sku,
                            'name' => $inv->product->name,
                            'quantity' => $quantity,
                            'status' => 'success',
                            'timestamp' => now(),
                        ];
                        $this->pushed++;
                    } else {
                        $pushErrors[] = "Product {$inv->product->sku}: Failed to push stock";
                        $this->failed++;
                    }
                }
            } catch (\Exception $e) {
                $pushErrors[] = "Product {$inv->product->sku}: " . $e->getMessage();
                $this->failed++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        // Step 4: Display results
        $this->displayPushSummary($pushUpdates);

        // Step 5: Log changes
        if (!empty($pushUpdates)) {
            if ($this->dryRun) {
                $this->warn("\n[DRY RUN] Stock updates would be pushed but no changes sent");
            } else {
                $this->info("\nStep 4: Recording push history...");
                $this->recordPushHistory($pushUpdates, $warehouse);
                $this->info('✓ Push history recorded');
            }
        }

        // Display final summary
        $this->newLine();
        $this->info('=== PUSH SUMMARY ===');
        $this->line("Pushed: {$this->pushed}");
        $this->line("Failed: {$this->failed}");
        $this->line("Total: " . ($this->pushed + $this->failed));

        if (!empty($pushErrors)) {
            $this->warn("\nErrors encountered:");
            foreach (array_slice($pushErrors, 0, 5) as $error) {
                $this->line("  ⚠ {$error}");
            }
            if (count($pushErrors) > 5) {
                $this->line("  ... and " . (count($pushErrors) - 5) . " more");
            }
        }

        // Log summary
        $this->logPushSummary($pushUpdates, $pushErrors, $warehouse);
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
     * Push stock level to Ordermentum for a specific variant
     * 
     * ENDPOINT: PUT https://stock.ordermentum.com/v1/items/{variant_id}
     * PAYLOAD: {"available": 27, "tracked": true}
     */
    private function pushStockToOrdermentum($variantId, $quantity, $product)
    {
        if (!$variantId) {
            throw new \Exception("Missing Ordermentum variant ID for {$product->sku}");
        }

        // Correct endpoint: /items/{variant_id}
        $url = $this->stockUrl . '/items/' . $variantId;

        // Simple payload with just available and tracked
        $payload = [
            'available' => (int)$quantity,
            'tracked' => true,
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT'); // PUT request
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->accessToken,
            'Content-Type: application/json',
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \Exception("CURL Error: {$error}");
        }

        // Success if 200 (OK) or 204 (No Content)
        if ($httpCode === 200 || $httpCode === 204) {
            Log::info('Stock pushed to Ordermentum', [
                'sku' => $product->sku,
                'quantity' => $quantity,
                'variant_id' => $variantId,
                'http_code' => $httpCode,
                'endpoint' => $url,
            ]);
            return true;
        }

        // Log error response
        Log::warning('Failed to push stock to Ordermentum', [
            'sku' => $product->sku,
            'quantity' => $quantity,
            'variant_id' => $variantId,
            'http_code' => $httpCode,
            'response' => $response,
            'endpoint' => $url,
            'payload' => $payload,
        ]);

        return false;
    }

    /**
     * Display push summary table
     */
    private function displayPushSummary($pushUpdates)
    {
        if (!empty($pushUpdates)) {
            $this->newLine();
            $this->info('Stock updates pushed:');
            $this->line(str_repeat('-', 100));
            $this->line(sprintf(
                '%-15s %-50s %-12s %-15s',
                'SKU',
                'Product Name',
                'Quantity',
                'Status'
            ));
            $this->line(str_repeat('-', 100));

            foreach ($pushUpdates as $update) {
                $name = substr($update['name'], 0, 48);
                $this->line(sprintf(
                    '%-15s %-50s %-12d %-15s',
                    $update['sku'],
                    $name,
                    $update['quantity'],
                    $update['status']
                ));
            }

            $this->line(str_repeat('-', 100));
        }
    }

    /**
     * Record push history in database (for audit trail)
     */
    private function recordPushHistory($pushUpdates, $warehouse)
    {
        try {
            foreach ($pushUpdates as $update) {
                // Create audit log entry
                DB::table('inventory_push_logs')->insert([
                    'inventory_id' => $update['inventory_id'],
                    'product_id' => $update['product_id'],
                    'warehouse_id' => $update['warehouse_id'],
                    'sku' => $update['sku'],
                    'pushed_quantity' => $update['quantity'],
                    'status' => $update['status'],
                    'pushed_at' => $update['timestamp'],
                    'created_at' => now(),
                ]);
            }
        } catch (\Exception $e) {
            $this->warn("Warning: Could not record push history - {$e->getMessage()}");
            // Don't fail the entire command if audit logging fails
            Log::warning('Failed to record push history', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Log push summary
     */
    private function logPushSummary($pushUpdates, $pushErrors, $warehouse)
    {
        $summary = [
            'timestamp' => now()->toIso8601String(),
            'warehouse_id' => $warehouse->id,
            'warehouse_name' => $warehouse->name,
            'pushed' => count($pushUpdates),
            'failed' => count($pushErrors),
            'dry_run' => $this->dryRun,
        ];

        Log::info('Stock push to Ordermentum completed', $summary);
    }
}
