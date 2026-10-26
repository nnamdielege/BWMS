<?php

namespace App\Enums;

enum OrderStatus: string
{
    case Open = 'open';                       // reserved, waiting to be picked
    case Picking = 'picking';                 // on an open pick list
    case PartiallyDispatched = 'partially_dispatched'; // some dispatched, rest backordered
    case Dispatched = 'dispatched';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return ucfirst(str_replace('_', ' ', $this->value));
    }

    public function isActive(): bool
    {
        return in_array($this, [self::Open, self::Picking, self::PartiallyDispatched], true);
    }
}
