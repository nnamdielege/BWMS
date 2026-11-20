<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A sample, gift, favour, staff or internal use: stock written off to someone, for a purpose, approved. */
class Giveaway extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'packs' => 'decimal:3',
            'qty_base' => 'integer',
            'base_qty_per_unit' => 'integer',
            'unit_cost' => 'decimal:4',
            'value' => 'decimal:2',
            'occurred_at' => 'datetime',
        ];
    }

    /** The customer's name, or the name typed in. */
    public function recipient(): string
    {
        return $this->customer?->name ?? (string) $this->recipient_name;
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'stock_movement_id');
    }

    public function reasonCode(): BelongsTo
    {
        return $this->belongsTo(ReasonCode::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(ProductUnit::class, 'product_unit_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
