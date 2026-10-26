<?php

namespace App\Services\Orders\Exceptions;

use App\Services\Stock\Exceptions\StockException;

/**
 * A reservation was refused (portal/manual orders, D-3). Nothing was reserved.
 * $lines feeds the portal's 409 response: [{line_ref, sku, requested, available}].
 */
class InsufficientAvailability extends StockException
{
    /** @param  list<array{line_ref: ?string, sku: string, requested: int, available: int}>  $lines */
    public function __construct(public readonly array $lines)
    {
        parent::__construct('Not enough stock available: '.implode(', ', array_map(
            fn ($l) => "{$l['sku']} (requested {$l['requested']}, available {$l['available']})", $lines,
        )).'.');
    }
}
