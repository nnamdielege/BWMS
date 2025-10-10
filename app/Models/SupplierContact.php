<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SupplierContact extends Model
{
    use HasFactory;

    protected $fillable = [
        'supplier_id',
        'name',
        'position',
        'email',
        'phone',
        'mobile',
        'is_primary',
        'notes',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
    ];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }
}