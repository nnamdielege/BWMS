<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired after a ledger transaction commits. Channel sync (step 6) listens to
 * this; it is never dispatched for a rolled-back transaction.
 */
class StockChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /** @param  list<int>  $productIds */
    public function __construct(public readonly array $productIds) {}
}
