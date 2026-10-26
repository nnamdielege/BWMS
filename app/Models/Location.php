<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Site > zone > bin hierarchy. Only bins hold stock.
 */
class Location extends Model
{
    use Auditable, HasFactory;

    public const TYPE_SITE = 'site';

    public const TYPE_ZONE = 'zone';

    public const TYPE_BIN = 'bin';

    public const TYPES = [self::TYPE_SITE, self::TYPE_ZONE, self::TYPE_BIN];

    protected $fillable = ['parent_id', 'type', 'code', 'name', 'is_sellable', 'is_quarantine', 'is_receiving', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return [
            'is_sellable' => 'boolean',
            'is_quarantine' => 'boolean',
            'is_receiving' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('code');
    }

    public function scopeBins(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_BIN);
    }

    /** Bins whose stock counts towards available-to-sell. */
    public function scopeSellable(Builder $query): Builder
    {
        return $query->bins()->where('is_sellable', true)->where('is_quarantine', false)->where('is_active', true);
    }

    public function isBin(): bool
    {
        return $this->type === self::TYPE_BIN;
    }

    /** "MAIN / COOL / A-01" style label. */
    public function path(): string
    {
        $parts = [];
        $node = $this;
        $guard = 0;
        while ($node && $guard++ < 10) {
            array_unshift($parts, $node->code);
            $node = $node->parent;
        }

        return implode(' / ', $parts);
    }

    public function site(): ?self
    {
        $node = $this;
        $guard = 0;
        while ($node && $node->type !== self::TYPE_SITE && $guard++ < 10) {
            $node = $node->parent;
        }

        return $node;
    }
}
