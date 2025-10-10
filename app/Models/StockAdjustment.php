<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockAdjustment extends Model
{
    use HasFactory;

    protected $fillable = [
        'adjustment_number',
        'warehouse_id',
        'product_id',
        'quantity_before',
        'quantity_adjusted',
        'quantity_after',
        'adjustment_type',
        'reason',
        'notes',
        'user_id',
    ];

    protected $casts = [
        'quantity_before' => 'integer',
        'quantity_adjusted' => 'integer',
        'quantity_after' => 'integer',
    ];

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}