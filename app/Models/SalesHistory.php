<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sales from before BWMS, one row per order line, for reorder demand only. Never an
 * order or a reservation. BWMS's own sales come from dispatch movements ("bwms").
 */
class SalesHistory extends Model
{
    public const SOURCE_ORDERMENTUM = 'ordermentum_history';

    public const SOURCE_PORTAL = 'portal_history';

    protected $table = 'sales_history';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['sale_date' => 'date', 'qty_base' => 'integer'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
