<?php

namespace App\Jobs;

use App\Models\Order;

/**
 * Staff cancelled a portal order, or the rest of one, in BWMS. What was cancelled
 * is fixed when it happens, so a retry hours later says exactly the same thing.
 * An order is only cancelled once, so the Idempotency-Key is stable per order.
 */
class SendPortalCancelEvent extends SendsPortalOrderEvent
{
    /** @param  list<array{line_ref: string, qty: int|float}>  $lines  sell units cancelled */
    public function __construct(public int $orderId, public array $lines, public ?string $reason, public string $at, public ?string $scope = null) {}

    protected function order(): ?Order
    {
        return Order::find($this->orderId);
    }

    protected function body(Order $order): ?array
    {
        return ['order_ref' => $order->external_ref, 'event' => 'cancelled', 'reason' => $this->reason, 'lines' => $this->lines, 'at' => $this->at];
    }

    protected function idempotencyKey(Order $order): string
    {
        // A line cancelled on its own (scope "line-…") has a key of its own; the whole order is cancelled once.
        return "bwms:order-event:{$order->external_ref}:cancelled".($this->scope ? ":{$this->scope}" : '');
    }
}
