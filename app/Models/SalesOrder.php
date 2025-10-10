<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalesOrder extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'order_number',
        'customer_id',
        'warehouse_id',
        'order_date',
        'expected_date',  // Make sure this is here
        'fulfilled_date',
        'status',
        'subtotal',
        'tax_rate',
        'tax',
        'shipping',
        'discount',
        'total',
        'notes',
    ];

    protected $casts = [
        'order_date' => 'date',
        'expected_date' => 'date',
        'fulfilled_date' => 'date',
        'subtotal' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'tax' => 'decimal:2',
        'shipping' => 'decimal:2',
        'discount' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function items()
    {
        return $this->hasMany(SalesOrderItem::class);
    }
}