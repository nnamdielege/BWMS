<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Barcode extends Model
{
    use Auditable;

    public const TYPES = ['ean13', 'ean8', 'upca', 'itf14', 'other'];

    protected $fillable = ['barcode', 'type'];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(ProductUnit::class, 'product_unit_id');
    }

    /** Best-effort type from the digits; GTIN check digits are validated in BarcodeRule. */
    public static function guessType(string $barcode): string
    {
        if (! ctype_digit($barcode)) {
            return 'other';
        }

        return match (strlen($barcode)) {
            8 => 'ean8',
            12 => 'upca',
            13 => 'ean13',
            14 => 'itf14',
            default => 'other',
        };
    }
}
