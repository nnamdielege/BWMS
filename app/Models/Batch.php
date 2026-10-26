<?php

namespace App\Models;

use App\Support\BusinessDate;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A lot of one product with an optional best-before date.
 */
class Batch extends Model
{
    use HasFactory;

    protected $fillable = ['product_id', 'lot_code', 'best_before', 'received_on', 'supplier_id'];

    protected function casts(): array
    {
        return [
            'best_before' => 'date',
            'received_on' => 'date',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Expired once the best-before date has passed (business timezone). D-13. */
    public function isExpired(): bool
    {
        return $this->best_before !== null && $this->best_before->toDateString() < BusinessDate::today();
    }

    public static function findOrCreateFor(Product $product, string $lotCode, ?string $bestBefore, ?string $receivedOn = null): self
    {
        return self::firstOrCreate(
            ['product_id' => $product->id, 'lot_code' => trim($lotCode), 'best_before' => $bestBefore],
            ['received_on' => $receivedOn],
        );
    }

    public function label(): string
    {
        return $this->lot_code.($this->best_before ? ' (BB '.$this->best_before->format('d/m/Y').')' : '');
    }
}
