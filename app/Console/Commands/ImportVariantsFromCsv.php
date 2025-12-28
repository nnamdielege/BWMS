<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ImportVariantsFromCsv extends Command
{
    protected $signature = 'import:variants-fresh {file}';
    protected $description = 'Import fresh variants from CSV into products table';

    public function handle()
    {
        $file = $this->argument('file');

        if (!file_exists($file)) {
            $file = base_path($file);
        }

        if (!file_exists($file)) {
            $this->error("File not found: {$file}");
            return;
        }

        $this->info("Reading {$file}...");

        $fp = fopen($file, 'r');
        if (!$fp) {
            $this->error("Cannot open file");
            return;
        }

        $header = fgetcsv($fp);
        $this->line("Found columns: " . implode(', ', array_slice($header, 0, 10)));

        $rowCount = 0;
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];

        $bar = $this->output->createProgressBar(0);
        $bar->start();

        while (($row = fgetcsv($fp)) !== false) {
            $rowCount++;

            if (count($row) !== count($header)) {
                $bar->advance();
                continue;
            }

            $data = array_combine($header, $row);

            try {
                $sku = trim($data['SKU*'] ?? $data['SKU'] ?? '');
                $variantId = trim($data['ordermentum_variant_id'] ?? '');
                $productId = trim($data['ordermentum_product_id'] ?? '');
                $name = trim($data['Name*'] ?? $data['Name'] ?? '');
                $salesCode = trim($data['SalesCode'] ?? '');

                // Skip if missing critical fields (name and sku only)
                if (empty($sku) || empty($name)) {
                    $skipped++;
                    $bar->advance();
                    continue;
                }

                // Get or create category
                $categoryNames = trim($data['Categories'] ?? '');
                $category = $this->getOrCreateCategory($categoryNames);

                // Prepare product data
                $productData = [
                    'sku' => $sku,
                    'name' => $name,
                    'description' => trim($data['Description'] ?? '') ?: null,
                    'barcode' => trim($data['No'] ?? '') ?: null,
                    'category_id' => $category->id,
                    'price' => (float)($data['Price*'] ?? $data['Price'] ?? 0),
                    'cost' => (float)($data['Cost'] ?? 0),
                    'unit_of_measure' => trim($data['Sales Unit'] ?? 'unit'),
                    'weight' => (float)($data['Weight'] ?? 0) ?: null,
                    'notes' => trim($data['Description'] ?? '') ?: null,
                    'is_active' => strtoupper(trim($data['Enabled'] ?? 'FALSE')) === 'TRUE',
                    'ordermentum_variant_id' => $variantId ?: null,
                    'ordermentum_product_id' => $productId ?: null,
                    'ordermentum_sku' => trim($sku) ?: null,
                    'sales_code' => $salesCode ?: null,
                ];

                // Update or create by ordermentum_variant_id
                $product = Product::updateOrCreate(
                    ['ordermentum_variant_id' => $variantId],
                    $productData
                );

                if ($product->wasRecentlyCreated) {
                    $created++;
                } else {
                    $updated++;
                }
            } catch (\Exception $e) {
                $errors[] = "Row {$rowCount}: " . $e->getMessage();
            }

            $bar->advance();
        }

        $bar->finish();
        fclose($fp);

        $this->newLine();
        $this->info("✓ Import completed!");
        $this->info("  Created: {$created}");
        $this->info("  Updated: {$updated}");
        $this->info("  Skipped: {$skipped}");
        $this->info("  Total rows: {$rowCount}");

        if (!empty($errors)) {
            $this->error("\nFirst 5 errors:");
            foreach (array_slice($errors, 0, 5) as $error) {
                $this->line("  - {$error}");
            }
            if (count($errors) > 5) {
                $this->line("  ... and " . (count($errors) - 5) . " more");
            }
        }
    }

    private function getOrCreateCategory($categoryNames)
    {
        if (empty($categoryNames)) {
            return ProductCategory::firstOrCreate(
                ['name' => 'Uncategorized'],
                ['slug' => 'uncategorized']
            );
        }

        // Get first category from pipe-separated list
        $names = array_map('trim', explode('|', $categoryNames));
        $categoryName = $names[0] ?? 'Uncategorized';

        return ProductCategory::firstOrCreate(
            ['name' => $categoryName],
            ['slug' => Str::slug($categoryName)]
        );
    }
}
