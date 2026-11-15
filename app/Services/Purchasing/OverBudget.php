<?php

namespace App\Services\Purchasing;

use App\Services\Stock\Exceptions\InvalidMovement;

/** Approving the PO would take the week over its purchasing budget, and no override was given. */
class OverBudget extends InvalidMovement
{
    /** @param  array{budget: ?float, committed: float, remaining: float, po_total: float}  $check */
    public function __construct(public readonly array $check)
    {
        $money = fn (float $v) => '$'.number_format($v, 2);
        parent::__construct($check['budget'] === null
            ? "No purchasing budget is set for this week. Approving {$money($check['po_total'])} needs an override and a reason."
            : "This PO ({$money($check['po_total'])} ex GST) is over this week's remaining budget ({$money($check['remaining'])} of {$money($check['budget'])}). Tick the override and give a reason to approve it.");
    }
}
