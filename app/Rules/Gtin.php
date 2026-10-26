<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Numeric barcodes of GTIN length (8/12/13/14) must carry a valid GS1 check
 * digit; this catches most mis-keyed or mis-scanned codes. Other formats
 * (internal labels, Code 128 text) are allowed as-is.
 */
class Gtin implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $value = (string) $value;

        if (! ctype_digit($value) || ! in_array(strlen($value), [8, 12, 13, 14], true)) {
            return;
        }

        if (! self::hasValidCheckDigit($value)) {
            $fail("The barcode {$value} has an invalid check digit.");
        }
    }

    public static function hasValidCheckDigit(string $digits): bool
    {
        $body = substr($digits, 0, -1);
        $sum = 0;
        // Weights alternate 3,1,3,... starting from the digit nearest the check digit.
        foreach (array_reverse(str_split($body)) as $i => $d) {
            $sum += (int) $d * ($i % 2 === 0 ? 3 : 1);
        }

        return (10 - $sum % 10) % 10 === (int) substr($digits, -1);
    }
}
