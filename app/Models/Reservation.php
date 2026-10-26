<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Stock held for an order line until dispatch (D-2). Written only by ReservationService. */
class Reservation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['qty_base' => 'integer'];
    }

    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(OrderLine::class);
    }
}
