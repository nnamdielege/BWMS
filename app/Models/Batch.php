<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Batch extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'batch_number',
        'manufacture_date',
        'expiry_date',
        'quantity',
        'notes',
    ];

    protected $casts = [
        'manufacture_date' => 'date',
        'expiry_date' => 'date',
        'quantity' => 'integer',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function scopeExpiringSoon($query, $days = 30)
    {
        return $query->whereDate('expiry_date', '<=', now()->addDays($days))
            ->whereDate('expiry_date', '>=', now());
    }

    public function scopeExpired($query)
    {
        return $query->whereDate('expiry_date', '<', now());
    }
}