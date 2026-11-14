<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A customer accepted another product in place of (part of) an order line. Written only by SubstitutionService. */
class OrderSubstitution extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'original_qty_base' => 'integer',
            'original_per' => 'integer',
            'original_unit_price' => 'decimal:4',
            'substitute_qty_base' => 'integer',
            'substitute_per' => 'integer',
            'substitute_unit_price' => 'decimal:4',
            'kept_original_price' => 'boolean',
            'price_difference' => 'decimal:2',
            'undone_at' => 'datetime',
            'channel_done_at' => 'datetime',
            'channel_undo_done_at' => 'datetime',
        ];
    }

    /** @param  Builder<OrderSubstitution>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('undone_at');
    }

    /** Value of a quantity at a per-pack price: null without a price. */
    public static function value(int $base, int $per, ?string $unitPrice): ?float
    {
        return $unitPrice === null ? null : round($base / max(1, $per) * (float) $unitPrice, 2);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function originalLine(): BelongsTo
    {
        return $this->belongsTo(OrderLine::class, 'original_line_id');
    }

    public function substituteLine(): BelongsTo
    {
        return $this->belongsTo(OrderLine::class, 'substitute_line_id');
    }

    public function originalProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'original_product_id');
    }

    public function substituteProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'substitute_product_id');
    }

    public function substituteUnit(): BelongsTo
    {
        return $this->belongsTo(ProductUnit::class, 'substitute_unit_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function undoer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'undone_by');
    }
}
