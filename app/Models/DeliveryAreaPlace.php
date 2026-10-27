<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A postcode (optionally narrowed to one suburb) served by a delivery area. */
class DeliveryAreaPlace extends Model
{
    public $timestamps = false;

    protected $fillable = ['delivery_area_id', 'postcode', 'suburb'];

    public function area(): BelongsTo
    {
        return $this->belongsTo(DeliveryArea::class, 'delivery_area_id');
    }
}
