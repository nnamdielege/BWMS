<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WarehouseLocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'warehouse_id',
        'location_code',
        'name',
        'location_type',
        'parent_id',
        'barcode',
        'capacity',
        'dimensions',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'capacity' => 'integer',
    ];

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function parent()
    {
        return $this->belongsTo(WarehouseLocation::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(WarehouseLocation::class, 'parent_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}