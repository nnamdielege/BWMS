<?php

namespace Tests\Support;

use App\Enums\OrderChannel;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Orders\ChannelOrderData;
use App\Services\Orders\OrderService;

trait OrderFixtures
{
    protected function manualOrder(User $actor, Product $product, int $qtyEach, bool $allowBackorder = false, string $policy = 'backorder', ?int $siteId = null): Order
    {
        return app(OrderService::class)->createManual($actor, [
            'customer_id' => Customer::factory()->create()->id,
            'site_id' => $siteId ?? $this->site()->id,
            'short_ship_policy' => $policy,
            'lines' => [['product_id' => $product->id, 'product_unit_id' => $product->units()->where('base_qty', 1)->value('id'), 'qty' => $qtyEach]],
        ], $allowBackorder);
    }

    /** @param  list<array{0: Product, 1: int, 2?: string}>  $lines  [product, qty each, line_ref] */
    protected function channelOrder(OrderChannel $channel, string $ref, int $version, array $lines, string $status = 'confirmed'): Order
    {
        return app(OrderService::class)->upsertFromChannel(new ChannelOrderData(
            channel: $channel,
            externalRef: $ref,
            version: $version,
            status: $status,
            siteId: $this->site()->id,
            lines: array_map(fn ($l, $i) => [
                'line_ref' => $l[2] ?? (string) ($i + 1),
                'product_id' => $l[0]->id,
                'product_unit_id' => $l[0]->units()->where('base_qty', 1)->value('id'),
                'qty' => $l[1],
            ], $lines, array_keys($lines)),
        ));
    }
}
