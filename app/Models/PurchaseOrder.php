<?php

namespace App\Models;

use App\Enums\PurchaseOrderStatus;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrder extends Model
{
    use Auditable;

    protected $fillable = ['supplier_id', 'site_id', 'order_date', 'expected_date', 'supplier_ref', 'notes'];

    protected function casts(): array
    {
        return [
            'status' => PurchaseOrderStatus::class,
            'order_date' => 'date',
            'expected_date' => 'date',
            'sent_at' => 'datetime',
            'closed_at' => 'datetime',
            'subtotal' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'site_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class)->orderBy('sort_order')->orderBy('id');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(Receipt::class)->latest('id');
    }

    public function emails(): HasMany
    {
        return $this->hasMany(PurchaseOrderEmail::class)->latest('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
