<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A product + pack size as listed on an external channel (Ordermentum variant).
 * Pushed quantity = floor(ATS_base / pack base_qty) - safety_buffer, never below 0.
 */
class ChannelListing extends Model
{
    use Auditable;

    protected $fillable = [
        'channel', 'product_id', 'product_unit_id', 'external_product_id', 'external_variant_id', 'external_sku',
        'external_location_id', 'sync_enabled', 'safety_buffer',
    ];

    protected function casts(): array
    {
        return [
            'sync_enabled' => 'boolean',
            'last_pushed_at' => 'datetime',
            'last_checked_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(ProductUnit::class, 'product_unit_id');
    }
}
