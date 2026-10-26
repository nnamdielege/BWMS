<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class OrderLine extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'qty' => 'integer',
            'base_qty_per_unit' => 'integer',
            'qty_base' => 'integer',
            'qty_dispatched_base' => 'integer',
            'qty_cancelled_base' => 'integer',
            'unit_price' => 'decimal:4',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(ProductUnit::class, 'product_unit_id');
    }

    public function reservation(): HasOne
    {
        return $this->hasOne(Reservation::class);
    }

    public function pickLines(): HasMany
    {
        return $this->hasMany(PickListLine::class);
    }

    /** Still to be dispatched (not dispatched, not cancelled). */
    public function outstandingBase(): int
    {
        return max(0, $this->qty_base - $this->qty_dispatched_base - $this->qty_cancelled_base);
    }
}
