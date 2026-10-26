<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * D-3: an order (normally Ordermentum) was accepted beyond available stock.
 * Listener logs a warning; the dashboard lists flagged orders. Step 6 adds
 * email/Slack alerting.
 */
class OrderOversold implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /** @param  list<string>  $skus */
    public function __construct(public readonly int $orderId, public readonly array $skus) {}
}
