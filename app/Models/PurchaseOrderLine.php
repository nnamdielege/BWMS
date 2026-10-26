<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderLine extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'qty_ordered' => 'integer',
            'base_qty_per_unit' => 'integer',
            'qty_ordered_base' => 'integer',
            'qty_received_base' => 'integer',
            'qty_rejected_base' => 'integer',
            'unit_cost' => 'decimal:4',
            'tax_rate' => 'decimal:2',
            'line_subtotal' => 'decimal:2',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(ProductUnit::class, 'product_unit_id');
    }

    public function outstandingBase(): int
    {
        return max(0, $this->qty_ordered_base - $this->qty_received_base);
    }

    /** Cost per base unit, ex GST. */
    public function unitCostPerBase(): string
    {
        return bcdiv((string) $this->unit_cost, (string) $this->base_qty_per_unit, 4);
    }
}
