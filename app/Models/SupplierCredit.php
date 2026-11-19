<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A credit expected from a supplier for stock returned to them from quarantine,
 * at cost when returned (ex GST). Open until the credit note arrives.
 */
class SupplierCredit extends Model
{
    use Auditable;

    public const STATUS_OPEN = 'open';

    public const STATUS_RECEIVED = 'received';

    protected $guarded = ['id'];

    protected $attributes = ['status' => self::STATUS_OPEN];

    protected function casts(): array
    {
        return [
            'qty_base' => 'integer',
            'unit_cost' => 'decimal:4',
            'amount' => 'decimal:2',
            'received_at' => 'datetime',
        ];
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_OPEN);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'stock_movement_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
