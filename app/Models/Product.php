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
        'sell_channels', 'is_stock_tracked',
    ];

    public const SELL_CHANNELS = ['none', 'portal', 'ordermentum', 'both'];

    /** Same defaults as the columns, so an unsaved or just-created model reads correctly. */
    protected $attributes = ['sell_channels' => 'ordermentum', 'is_stock_tracked' => true];

    protected function casts(): array
    {
        return [
            'is_batch_tracked' => 'boolean',
            'is_active' => 'boolean',
            'is_stock_tracked' => 'boolean',
            'avg_cost' => 'decimal:4',
            'sell_price' => 'decimal:2',
        ];
    }

    /** Whether the product is sold on a channel ('ordermentum' or 'portal'). */
    public function sellsOn(string $channel): bool
    {
        return $this->sell_channels === 'both' || $this->sell_channels === $channel;
    }

    /** Mapping a channel listing means the product is sold there. */
    public function addSellChannel(string $channel): void
    {
        if ($this->sellsOn($channel)) {
            return;
        }
        $this->update(['sell_channels' => in_array($this->sell_channels, ['none', $channel], true) ? $channel : 'both']);
    }

    /** sell_channels values that include a channel, for queries. */
    public static function channelsIncluding(string $channel): array
    {
        return [$channel, 'both'];
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
