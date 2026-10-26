<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A stock-keeping item. All stock quantities are integers in `base_unit`.
 * Pack sizes ("variants") are ProductUnits.
 */
class Product extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    protected $fillable = [
        'sku', 'name', 'description', 'category_id', 'range_id', 'base_unit', 'is_batch_tracked',
        'shelf_life_days', 'short_dated_days', 'reorder_point', 'reorder_qty', 'sell_price', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_batch_tracked' => 'boolean',
            'is_active' => 'boolean',
            'avg_cost' => 'decimal:4',
            'sell_price' => 'decimal:2',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function range(): BelongsTo
    {
        return $this->belongsTo(Range::class);
    }

    public function units(): HasMany
    {
        return $this->hasMany(ProductUnit::class)->orderBy('base_qty');
    }

    public function channelListings(): HasMany
    {
        return $this->hasMany(ChannelListing::class);
    }

    public function batches(): HasMany
    {
        return $this->hasMany(Batch::class);
    }

    public function baseUnit(): HasOne
    {
        return $this->hasOne(ProductUnit::class)->where('is_base', true);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q
            ->where('sku', 'like', "%{$term}%")
            ->orWhere('name', 'like', "%{$term}%")
            ->orWhereHas('units.barcodes', fn (Builder $b) => $b->where('barcode', $term)));
    }
}
