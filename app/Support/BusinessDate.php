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
}
