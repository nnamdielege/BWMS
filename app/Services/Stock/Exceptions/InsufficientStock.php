<?php

namespace App\Services\Stock\Exceptions;

class InsufficientStock extends StockException
{
    public static function for(string $sku, string $location, int $onHand, int $requested): self
    {
        return new self("Not enough stock of {$sku} in {$location}: {$onHand} on hand, {$requested} requested.");
    }
}
