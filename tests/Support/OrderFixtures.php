<?php

namespace Tests\Support;

use App\Enums\OrderChannel;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Orders\ChannelOrderData;
use App\Services\Orders\OrderService;
use App\Services\Orders\PickListService;

trait OrderFixtures
{
    /**
     * A manual order for $qtyEach units of $product, picked and dispatched now: one pick list,
     * $picked units picked (all unless given). For the stock sold and exception reports.
     */
    protected function dispatchedSale(User $actor, Product $product, int $qtyEach, ?Customer $customer = null, ?string $unitPrice = null,
        OrderChannel $channel = OrderChannel::Manual, ?int $picked = null): Order
    {
        $order = app(OrderService::class)->createManual($actor, [
            'customer_id' => ($customer ?? Customer::factory()->create())->id,
            'site_id' => $this->site()->id,
            'short_ship_policy' => 'cancel',
            'lines' => [['product_id' => $product->id, 'product_unit_id' => $product->units()->where('base_qty', 1)->value('id'), 'qty' => $qtyEach, 'unit_price' => $unitPrice]],
        ], true);
        $order->forceFill(['channel' => $channel])->save();
        $pickLists = app(PickListService::class);
        $list = $pickLists->create($actor, $this->site(), [$order->id])['pickList'];
        $pickLists->recordPicked($list, $list->lines()->pluck('qty_to_pick_base', 'id')->map(fn ($q) => $picked ?? $q)->all());
        $pickLists->dispatch($list->fresh(), $actor);

        return $order->fresh();
    }

    protected function manualOrder(User $actor, Product $product, int $qtyEach, bool $allowBackorder = false, string $policy = 'backorder', ?int $siteId = null, ?Customer $customer = null): Order
    {
        return app(OrderService::class)->createManual($actor, [
            'customer_id' => ($customer ?? Customer::factory()->create())->id,
            'site_id' => $siteId ?? $this->site()->id,
            'short_ship_policy' => $policy,
            'lines' => [['product_id' => $product->id, 'product_unit_id' => $product->units()->where('base_qty', 1)->value('id'), 'qty' => $qtyEach]],
        ], $allowBackorder);
    }

    /** @param  list<array{0: Product, 1: int, 2?: string}>  $lines  [product, qty each, line_ref] */
    protected function channelOrder(OrderChannel $channel, string $ref, int $version, array $lines, string $status = 'confirmed', array $delivery = [], ?string $date = null): Order
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
            requestedDeliveryDate: $date,
            delivery: $delivery,
        ));
    }
}
