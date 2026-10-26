<?php

namespace Tests\Support;

use App\Integrations\Ordermentum\OrdermentumClient;
use App\Models\ChannelListing;
use App\Models\Product;
use Illuminate\Support\Sleep;

trait OrdermentumFixtures
{
    protected function configureOrdermentum(array $switches = []): void
    {
        config([
            'services.ordermentum' => [
                'api_key' => 'om_api_test',
                'base_url' => 'https://api.om.test', 'stock_url' => 'https://stock.om.test',
                'supplier_id' => 'sup-1', 'location_id' => 'loc-1',
                'connect_timeout' => 5, 'timeout' => 15, 'max_retry_wait' => 30,
                'context_headers' => 'plain',
                'rate_limits' => [], // budgets are tested on their own
            ],
            'bwms.ordermentum.push_enabled' => $switches['push'] ?? true,
            'bwms.ordermentum.ingest_enabled' => $switches['ingest'] ?? true,
            'bwms.ordermentum.webhook_token' => 'hook-secret-123',
        ]);
        $this->app->forgetInstance(OrdermentumClient::class);
        Sleep::fake(syncWithCarbon: true);
    }

    protected function listing(Product $product, string $variant, bool $enabled = true, ?int $unitBaseQty = null): ChannelListing
    {
        $unit = $product->units()->where('base_qty', $unitBaseQty ?? 1)->firstOrFail();

        return ChannelListing::create([
            'channel' => 'ordermentum', 'product_id' => $product->id, 'product_unit_id' => $unit->id,
            'external_variant_id' => $variant, 'sync_enabled' => $enabled,
        ]);
    }
}
