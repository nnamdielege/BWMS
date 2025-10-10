<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'customer_code',
        'company_name',
        'name',
        'email',
        'phone',
        'mobile',
        'address',
        'city',
        'state',
        'postal_code',
        'country',
        'tax_id',
        'payment_terms',
        'credit_limit',
        'notes',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'credit_limit' => 'decimal:2',
    ];

    public function salesOrders()
    {
        return $this->hasMany(SalesOrder::class);
    }
}