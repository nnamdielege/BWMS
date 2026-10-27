<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PickListLine extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'qty_to_pick_base' => 'integer',
            'qty_picked_base' => 'integer',
            'bin_scanned_at' => 'datetime',
            'product_scanned_at' => 'datetime',
            'picked_at' => 'datetime',
        ];
    }

    public function pickList(): BelongsTo
    {
        return $this->belongsTo(PickList::class);
    }

    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(OrderLine::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }
}
