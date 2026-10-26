<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "Count this bin": raised automatically when dispatch takes a bin below zero.
 * Closed by posting a stocktake that covers the bin, or dismissed by a manager.
 */
class CountTask extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_DONE = 'done';

    public const STATUS_DISMISSED = 'dismissed';

    public const REASON_NEGATIVE_AFTER_DISPATCH = 'negative_after_dispatch';

    protected $guarded = ['id', 'open_location_id'];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function stocktake(): BelongsTo
    {
        return $this->belongsTo(Stocktake::class);
    }
}
