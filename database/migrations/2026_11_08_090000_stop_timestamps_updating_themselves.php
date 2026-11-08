<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * With explicit_defaults_for_timestamp=0 (MariaDB's default, and production's
 * 10.5), a table's first NOT NULL TIMESTAMP silently gets DEFAULT CURRENT_TIMESTAMP
 * ON UPDATE CURRENT_TIMESTAMP, so every update to the row rewrote these. Redefined
 * with an explicit default and no ON UPDATE. On MySQL 8 (where the flag defaults
 * on) the columns end up the same way. bwms:check-timestamps lists rows already
 * overwritten.
 */
return new class extends Migration
{
    public const COLUMNS = [
        'stocktakes' => 'snapshot_at',
        'stock_movements' => 'occurred_at',
        'receipts' => 'received_at',
        'order_packs' => 'packed_at',
        'user_invitations' => 'expires_at',
    ];

    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }
        foreach (self::COLUMNS as $table => $column) {
            DB::statement("ALTER TABLE `{$table}` MODIFY `{$column}` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP");
        }
    }

    public function down(): void
    {
        // Nothing to undo: putting ON UPDATE back would only bring the bug back.
    }
};
