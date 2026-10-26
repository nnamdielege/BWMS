<?php

namespace App\Enums;

/**
 * Every way stock can change. Spec §B2.2.
 */
enum MovementType: string
{
    case OpeningBalance = 'opening_balance';
    case Receipt = 'receipt';
    case Sale = 'sale';
    case SaleReversal = 'sale_reversal';
    case Adjustment = 'adjustment';
    case WriteOff = 'write_off';
    case TransferOut = 'transfer_out';
    case TransferIn = 'transfer_in';
    case StocktakeVariance = 'stocktake_variance';
    case ReturnToSupplier = 'return_to_supplier';
    case CustomerReturn = 'customer_return';

    /** +1 must be positive, -1 must be negative, 0 either sign. */
    public function sign(): int
    {
        return match ($this) {
            self::OpeningBalance, self::Receipt, self::SaleReversal, self::TransferIn, self::CustomerReturn => 1,
            self::Sale, self::WriteOff, self::TransferOut, self::ReturnToSupplier => -1,
            self::Adjustment, self::StocktakeVariance => 0,
        };
    }

    public function requiresReason(): bool
    {
        return in_array($this, [self::Adjustment, self::WriteOff], true);
    }

    public function label(): string
    {
        return ucfirst(str_replace('_', ' ', $this->value));
    }
}
