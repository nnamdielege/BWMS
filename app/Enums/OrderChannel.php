<?php

namespace App\Enums;

enum OrderChannel: string
{
    case Manual = 'manual';
    case Portal = 'portal';
    case Ordermentum = 'ordermentum';

    /** D-3: Ordermentum orders are always accepted; portal and manual are blocked when short. */
    public function alwaysAccept(): bool
    {
        return $this === self::Ordermentum;
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
