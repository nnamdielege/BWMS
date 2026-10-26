<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

/**
 * A pack size of a product, e.g. "Each" (base_qty 1) or "Carton" (base_qty 12).
 */
class ProductUnit extends Model
{
    use Auditable, HasFactory;

    protected $fillable = ['name', 'code', 'base_qty', 'is_base', 'purchasable', 'sellable', 'default_purchase', 'default_sale'];

    protected function casts(): array
    {
        return [
            'base_qty' => 'integer',
            'is_base' => 'boolean',
            'purchasable' => 'boolean',
            'sellable' => 'boolean',
            'default_purchase' => 'boolean',
            'default_sale' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function barcodes(): HasMany
    {
        return $this->hasMany(Barcode::class);
    }

    /** Convert a quantity of this unit to base units. */
    public function toBase(int $qty): int
    {
        if ($qty < 0) {
            throw new InvalidArgumentException('Quantity must not be negative.');
        }

        return $qty * $this->base_qty;
    }

    /** Whole units of this pack obtainable from a base quantity (rounds down). */
    public function wholeUnitsFromBase(int $baseQty): int
    {
        return intdiv(max(0, $baseQty), $this->base_qty);
    }
}
