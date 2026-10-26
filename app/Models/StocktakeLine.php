<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StocktakeLine extends Model
{
    protected $fillable = ['product_id', 'location_id', 'batch_id', 'batch_key', 'snapshot_qty'];

    protected function casts(): array
    {
        return [
            'snapshot_qty' => 'integer',
            'counted_qty' => 'integer',
            'system_qty_at_count' => 'integer',
            'posted_variance' => 'integer',
            'counted_at' => 'datetime',
        ];
    }

    public function stocktake(): BelongsTo
    {
        return $this->belongsTo(Stocktake::class);
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

    /** Variance to post: counted minus what the system held when the count was taken. */
    public function variance(): ?int
    {
        return $this->counted_qty === null ? null : $this->counted_qty - (int) $this->system_qty_at_count;
    }
}
