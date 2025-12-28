<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportProductVariants extends Command
{
    protected $signature = 'import:variants {file}';
    protected $description = 'Import product variants from CSV file to database';

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

        // Read header
        $header = fgetcsv($fp);
        $this->line("Columns: " . implode(', ', $header));

        $variants = [];
        $count = 0;

        while (($row = fgetcsv($fp)) !== false) {
            $count++;
            if ($count === 1) continue; // Skip first data row if it's a duplicate header

            if (count($row) !== count($header)) {
                continue; // Skip malformed rows
            }

            $data = array_combine($header, $row);

            $variant = [
                'sku' => trim($data['SKU*'] ?? $data['SKU'] ?? ''),
                'product_id' => trim($data['Product ID'] ?? ''),
                'variant_id' => trim($data['Variant ID'] ?? ''),
                'name' => trim($data['Name*'] ?? $data['Name'] ?? ''),
                'description' => trim($data['Description'] ?? ''),
                'price' => (float)($data['Price*'] ?? $data['Price'] ?? 0),
                'cost' => (float)($data['Cost'] ?? 0),
                'tax_code' => trim($data['TaxCode'] ?? ''),
                'sales_code' => trim($data['SalesCode'] ?? ''),
                'categories' => trim($data['Categories'] ?? ''),
                'enabled' => strtoupper(trim($data['Enabled'] ?? 'FALSE')) === 'TRUE' ? 1 : 0,
                'base_plus_tax' => (float)($data['Base + Tax'] ?? 0),
                'has_image' => strtoupper(trim($data['Image'] ?? 'No')) === 'YES' ? 1 : 0,
                'created_at_ordermentum' => $this->parseDate($data['Created At'] ?? null),
                'created_by' => trim($data['Created By'] ?? ''),
                'updated_at_ordermentum' => $this->parseDate($data['Updated At'] ?? null),
                'updated_by' => trim($data['Updated By'] ?? ''),
                'reference_id' => trim($data['Reference ID'] ?? ''),
                'groups' => trim($data['Groups'] ?? ''),
                'sales_unit' => trim($data['Sales Unit'] ?? 'unit'),
                'multiples_of' => (int)($data['MultiplesOf'] ?? 1),
                'weight' => (float)($data['Weight'] ?? 1),
                'pricing_unit' => trim($data['Pricing Unit'] ?? 'unit'),
                'highlight_badge' => trim($data['Highlight Badge'] ?? ''),
                'minimum_quantity' => (int)($data['MinimumQuantity'] ?? 1),
                'delivery_days' => $this->parseDeliveryDays($data['Delivery Days (excluded)'] ?? ''),
                'shipped_quantity' => (int)($data['ShippedQuantity'] ?? 0),
                'stock_availability' => trim($data['Stock Availability'] ?? 'Unknown'),
                'created_at' => now(),
                'updated_at' => now(),
            ];

            // Skip if missing critical fields
            if (empty($variant['variant_id']) || empty($variant['product_id'])) {
                continue;
            }

            $variants[] = $variant;

            // Insert in batches of 50
            if (count($variants) >= 50) {
                $this->insertBatch($variants);
                $variants = [];
            }
        }

        // Insert remaining
        if (!empty($variants)) {
            $this->insertBatch($variants);
        }

        fclose($fp);
        $this->info("✓ Import completed! Processed {$count} rows");
    }

    private function insertBatch($variants)
    {
        try {
            DB::table('ordermentum_products')->upsert(
                $variants,
                ['variant_id'], // Unique key
                array_keys($variants[0]) // Update columns
            );
            $this->line("  ✓ Inserted/updated " . count($variants) . " variants");
        } catch (\Exception $e) {
            $this->error("Error inserting batch: " . $e->getMessage());
        }
    }

    private function parseDate($dateString)
    {
        if (!$dateString || $dateString === '') {
            return null;
        }

        try {
            // Try DD-MM-YY format first (24-10-25)
            $date = \DateTime::createFromFormat('d-m-y H:i', $dateString);
            if ($date) {
                return $date;
            }

            // Try with just date
            $date = \DateTime::createFromFormat('d-m-y', trim($dateString));
            if ($date) {
                return $date;
            }

            // Try ISO format
            return new \DateTime($dateString);
        } catch (\Exception $e) {
            return null;
        }
    }

    private function parseDeliveryDays($daysString)
    {
        if (!$daysString || $daysString === '') {
            return null;
        }

        // Remove brackets and parse
        $daysString = trim($daysString, '[]"');
        if (empty($daysString)) {
            return null;
        }

        // Split by comma
        $days = array_map('trim', explode(',', $daysString));

        // Remove quotes
        $days = array_map(function ($day) {
            return trim($day, '"\'');
        }, $days);

        return json_encode($days);
    }
}
