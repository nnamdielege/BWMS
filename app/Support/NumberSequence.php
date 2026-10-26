<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Concurrency-safe document numbers, e.g. PO-2026-00001 (D-12). Must be called
 * inside a transaction: the sequence row stays locked until commit, so two
 * concurrent callers can never get the same number. A rollback gives the
 * number back, so numbers stay gap-free.
 */
final class NumberSequence
{
    public static function next(string $name, string $prefix): string
    {
        $period = Carbon::now(config('bwms.business_timezone'))->format('Y');

        DB::table('number_sequences')->insertOrIgnore(['name' => $name, 'period' => $period, 'last_value' => 0]);
        $row = DB::table('number_sequences')->where('name', $name)->where('period', $period)->lockForUpdate()->first();
        $value = $row->last_value + 1;
        DB::table('number_sequences')->where('name', $name)->where('period', $period)->update(['last_value' => $value]);

        return sprintf('%s-%s-%05d', $prefix, $period, $value);
    }
}
