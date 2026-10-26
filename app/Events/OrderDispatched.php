<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** Goods left the warehouse. Step 7 notifies the portal (order-events contract). */
class OrderDispatched implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /** @param  list<int>  $orderIds */
    public function __construct(public readonly int $pickListId, public readonly array $orderIds) {}
}
