<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
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

        try {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER stock_movements_no_update BEFORE UPDATE ON stock_movements
                FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'stock_movements is append-only'
            SQL);

            DB::unprepared(<<<'SQL'
                CREATE TRIGGER stock_movements_no_delete BEFORE DELETE ON stock_movements
                FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'stock_movements is append-only'
            SQL);
        } catch (QueryException $e) {
            // Fail closed, but say how to fix it. Verified on MariaDB 10.5.29
            // with binary logging on: a non-SUPER user gets error 1419.
            if (in_array($e->errorInfo[1] ?? null, [1419, 1142, 1227], true)) {
                throw new RuntimeException(
                    'Cannot create ledger triggers: the DB user lacks the TRIGGER privilege, or binary logging is on '
                    .'without log_bin_trust_function_creators=1. Fix on the server (preferred): set '
                    .'log_bin_trust_function_creators=1 in the MariaDB config and GRANT TRIGGER on the schema; '
                    .'or create the triggers once as an admin user. Only as a recorded last resort set '
                    .'BWMS_LEDGER_DB_TRIGGERS=false. Original error: '.$e->getMessage(),
                    previous: $e,
                );
            }
            throw $e;
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS stock_movements_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS stock_movements_no_delete');
    }
};
