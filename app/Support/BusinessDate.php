<?php

namespace App\Support;

use Illuminate\Support\Carbon;

final class BusinessDate
{
    /** Today's date (Y-m-d) in the business timezone (Australia/Brisbane). */
    public static function today(): string
    {
        return Carbon::now(config('bwms.business_timezone'))->toDateString();
    }

    /**
     * A source's date as a business-timezone Y-m-d. A timestamp takes its local date,
     * so Ordermentum's 2026-03-09T14:00:00.000Z is 10 March; a plain date stays as it
     * is. Null for anything else, including impossible dates, which Carbon would roll over.
     */
    public static function parse(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})/', trim($value), $m) || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }
        $tz = config('bwms.business_timezone');
        try {
            return Carbon::parse(trim($value), $tz)->setTimezone($tz)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
