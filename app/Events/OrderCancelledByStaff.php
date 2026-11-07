<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** Staff cancelled an order, or the rest of one, in BWMS. A channel's own cancel doesn't fire this. */
class OrderCancelledByStaff implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /** @param  list<array{line_ref: string, qty: int|float}>  $lines  sell units cancelled */
    public function __construct(public readonly int $orderId, public readonly array $lines, public readonly ?string $reason, public readonly string $at) {}
}
