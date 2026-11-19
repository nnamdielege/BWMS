<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Why stock was put into a quarantine bin: one row per movement into quarantine.
 * The stock itself is in the ledger; this only carries the reason.
 */
class QuarantineEntry extends Model
{
    public const UPDATED_AT = null;

    public const REASONS = [
        'expired' => 'Past best-before',
        'damaged' => 'Damaged',
        'recall' => 'Recall',
        'quality' => 'Quality',
    ];

    protected $guarded = ['id'];

    public static function label(?string $reason): ?string
    {
        return $reason === null ? null : (self::REASONS[$reason] ?? $reason);
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'stock_movement_id');
    }
}
