<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReceiptLine extends Model
{
    public const REASONS = ['damaged', 'wrong_item', 'expired', 'short_dated', 'quality', 'over', 'other'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'qty_accepted_base' => 'integer',
            'qty_damaged_base' => 'integer',
            'qty_rejected_base' => 'integer',
        ];
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(Receipt::class);
    }

    public function poLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class, 'purchase_order_line_id');
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
