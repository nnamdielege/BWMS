<?php

namespace App\Enums;

/**
 * suggested/draft -> approved -> sent -> partially_received -> received -> paid,
 * or closed (short) / cancelled. Approval runs the weekly budget check; nothing is
 * sent before it.
 */
enum PurchaseOrderStatus: string
{
    case Suggested = 'suggested'; // made by purchasing:suggest, refreshed until approved
    case Draft = 'draft';
    case Approved = 'approved';
    case Sent = 'sent';
    case PartiallyReceived = 'partially_received';
    case Received = 'received';
    case Paid = 'paid';
    case Closed = 'closed';       // closed short, or legacy history
    case Cancelled = 'cancelled';

    /** Counted against the week's budget, by approved_at. */
    public const COMMITTED = [self::Approved, self::Sent, self::PartiallyReceived, self::Received, self::Paid];

    /**
     * Can be paid (Mark paid), and shows in cash-out until it is (paid_at). Pre-payment of an approved
     * or sent PO and paying for a short-shipped (closed) one included.
     */
    public const PAYABLE = [self::Approved, self::Sent, self::PartiallyReceived, self::Received, self::Closed];

    public function label(): string
    {
        return match ($this) {
            self::PartiallyReceived => 'Partially received',
            default => ucfirst($this->value),
        };
    }

    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Suggested], true);
    }

    public function canApprove(): bool
    {
        return $this->isEditable();
    }

    public function canReceive(): bool
    {
        return in_array($this, [self::Sent, self::PartiallyReceived], true);
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Suggested, self::Draft, self::Approved, self::Sent, self::PartiallyReceived], true);
    }

    /** @return list<string> */
    public static function values(array $cases): array
    {
        return array_map(fn (self $s) => $s->value, $cases);
    }
}
