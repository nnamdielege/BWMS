<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Supplier extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'supplier_code',
        'company_name',
        'contact_name',
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

    public function purchaseOrders()
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function products()
    {
        return $this->belongsToMany(Product::class, 'product_supplier')
            ->withPivot('supplier_sku', 'cost', 'lead_time_days')
            ->withTimestamps();
    }
}