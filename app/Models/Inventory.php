<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Inventory extends Model
{
    use HasFactory;

    // Specify the table name explicitly
    protected $table = 'inventory';  // Add this line

    protected $fillable = [
        'product_id',
        'warehouse_id',
        'quantity_on_hand',
        'quantity_available',
        'quantity_allocated',
        'quantity_on_order',
        'bin_location',
        'notes',
    ];

    protected $casts = [
        'quantity_on_hand' => 'integer',
        'quantity_available' => 'integer',
        'quantity_allocated' => 'integer',
        'quantity_on_order' => 'integer',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }
}