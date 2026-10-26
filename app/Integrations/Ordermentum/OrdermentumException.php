<?php

namespace App\Integrations\Ordermentum;

use RuntimeException;

class OrdermentumException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $status = null, public readonly ?int $retryAfter = null)
    {
        parent::__construct($message);
    }
}
