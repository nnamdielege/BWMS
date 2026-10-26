<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * NFR-9: the ledger is append-only at the database level, not just in Eloquent.
 * Requires the TRIGGER privilege (and, on MySQL with binary logging,
 * log_bin_trust_function_creators=1). Set BWMS_LEDGER_DB_TRIGGERS=false only
 * if the host cannot grant that, and record the decision.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! config('bwms.ledger_db_triggers') || DB::getDriverName() !== 'mysql' && DB::getDriverName() !== 'mariadb') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER stock_movements_no_update BEFORE UPDATE ON stock_movements
            FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'stock_movements is append-only'
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER stock_movements_no_delete BEFORE DELETE ON stock_movements
            FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'stock_movements is append-only'
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS stock_movements_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS stock_movements_no_delete');
    }
};
