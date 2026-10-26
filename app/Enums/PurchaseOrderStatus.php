<?php

namespace App\Enums;

enum PurchaseOrderStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case PartiallyReceived = 'partially_received';
    case Received = 'received';
    case Closed = 'closed';       // closed short, or legacy history
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::PartiallyReceived => 'Partially received',
            self::Closed => 'Closed',
            default => ucfirst($this->value),
        };
    }

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    public function canReceive(): bool
    {
        return in_array($this, [self::Sent, self::PartiallyReceived], true);
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Draft, self::Sent, self::PartiallyReceived], true);
    }
}
