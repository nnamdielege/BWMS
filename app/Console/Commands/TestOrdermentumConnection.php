<?php

namespace App\Console\Commands;

use App\Services\OrdermentumApiService;
use Illuminate\Console\Command;


class TestOrdermentumConnection extends Command
{
    protected $signature = 'ordermentum:test';
    protected $description = 'Test Ordermentum API connection and authentication';

    public function handle(OrdermentumApiService $ordermentum)
    {
        $this->info('Testing Ordermentum API authentication...');

        if ($ordermentum->testConnection()) {
            $this->info('✓ Authentication successful!');

            // Try to fetch first page of products
            $this->info('Fetching sample products...');
            $products = $ordermentum->getProducts(1, 5);

            if ($products && isset($products['data'])) {
                $this->info('✓ Products fetched: ' . count($products['data']));
                $this->table(
                    ['ID', 'Name', 'SKU'],
                    collect($products['data'])->take(5)->map(function ($product) {
                        return [
                            $product['id'] ?? 'N/A',
                            $product['name'] ?? 'N/A',
                            $product['sku'] ?? 'N/A',
                        ];
                    })->toArray()
                );
            }

            return 0;
        }

        $this->error('✗ Connection failed. Check your credentials in .env file.');
        return 1;
    }
}
