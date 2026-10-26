<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Projection of the ledger per product x bin x batch. Written only by StockLedger
 * (and stock:rebuild-balances); never edit directly.
 */
class StockBalance extends Model
{
    public const CREATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['on_hand' => 'integer'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }
}
