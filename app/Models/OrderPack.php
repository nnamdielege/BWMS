<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An order packed from one pick list: carton count, who packed it and when. */
class OrderPack extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['packed_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function packer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'packed_by');
    }
}
