<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Concurrency-safe document numbers (D-12). Call inside a transaction: the
 * sequence row stays X-locked until commit, so two callers never get the same
 * number, and a rollback gives the number back (gap-free).
 *
 *   next('order', 'SO')                 SO-2026-00001  (restarts each year)
 *   daily('purchase_order', 'PO')       PO-20261113-0001 (restarts each day)
 *
 * The period is the business-timezone (Australia/Brisbane) date or year.
 *
 * One atomic statement inserts-or-increments the row. Avoid "SELECT ... FOR
 * UPDATE, then insert if missing": on a missing row the locking read takes a
 * gap lock, and concurrent first uses then deadlock on each other's inserts
 * (seen in StockLedgerConcurrencyTest). LAST_INSERT_ID(expr) is per connection.
 */
final class NumberSequence
{
    public static function next(string $name, string $prefix): string
    {
        $period = Carbon::now(config('bwms.business_timezone'))->format('Y');

        return sprintf('%s-%s-%05d', $prefix, $period, self::increment($name, $period));
    }

    /** Date then a 4-digit sequence that restarts at 0001 each business day. */
    public static function daily(string $name, string $prefix): string
    {
        $period = Carbon::now(config('bwms.business_timezone'))->format('Ymd');

        return sprintf('%s-%s-%04d', $prefix, $period, self::increment($name, $period));
    }

    private static function increment(string $name, string $period): int
    {
        DB::statement(
            'INSERT INTO number_sequences (name, period, last_value) VALUES (?, ?, LAST_INSERT_ID(1))
             ON DUPLICATE KEY UPDATE last_value = LAST_INSERT_ID(last_value + 1)',
            [$name, $period],
        );

        return (int) DB::selectOne('SELECT LAST_INSERT_ID() AS v')->v;
    }
}
