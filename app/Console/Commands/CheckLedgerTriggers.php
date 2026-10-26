<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Verifies the stock_movements immutability triggers exist (NFR-9). Run after
 * every deploy (docs/DEPLOY.md) and daily from the scheduler. Exits non-zero,
 * prints remediation and logs a critical error if anything is missing.
 */
class CheckLedgerTriggers extends Command
{
    protected $signature = 'bwms:check-triggers';

    protected $description = 'Fail loudly unless the stock ledger immutability triggers are installed';

    /** trigger name => [event, table] */
    public const REQUIRED = [
        'stock_movements_no_update' => ['UPDATE', 'stock_movements'],
        'stock_movements_no_delete' => ['DELETE', 'stock_movements'],
    ];

    public function handle(): int
    {
        if (! config('bwms.ledger_db_triggers')) {
            return $this->failLoudly(['Ledger triggers are DISABLED (BWMS_LEDGER_DB_TRIGGERS=false). The ledger is only protected by application code.']);
        }

        $found = DB::table('information_schema.triggers')
            ->where('trigger_schema', DB::getDatabaseName())
            ->whereIn('trigger_name', array_keys(self::REQUIRED))
            ->get(['trigger_name', 'event_manipulation', 'event_object_table', 'action_timing', 'action_statement'])
            ->keyBy(fn ($t) => $t->trigger_name ?? $t->TRIGGER_NAME);

        $problems = [];
        foreach (self::REQUIRED as $name => [$event, $table]) {
            $t = $found[$name] ?? null;
            if (! $t) {
                $problems[] = "Missing trigger {$name}.";

                continue;
            }
            $t = array_change_key_case((array) $t, CASE_LOWER);
            if ($t['event_manipulation'] !== $event || $t['event_object_table'] !== $table || $t['action_timing'] !== 'BEFORE'
                || ! str_contains(strtoupper($t['action_statement']), 'SIGNAL')) {
                $problems[] = "Trigger {$name} exists but is not the expected BEFORE {$event} ON {$table} ... SIGNAL.";
            }
        }

        if ($problems !== []) {
            return $this->failLoudly($problems);
        }

        $this->info('OK: stock_movements immutability triggers are installed.');

        return self::SUCCESS;
    }

    /** @param  list<string>  $problems */
    private function failLoudly(array $problems): int
    {
        foreach ($problems as $p) {
            $this->error($p);
        }
        $this->line('');
        $this->line('Fix (see docs/DEPLOY.md):');
        $this->line('  1. In the MariaDB server config: log_bin_trust_function_creators = 1 (needed when binary logging is on)');
        $this->line('  2. GRANT TRIGGER ON <schema>.* TO <app user>;');
        $this->line('  3. php artisan migrate:refresh --path=database/migrations/2026_10_26_100100_add_stock_movement_immutability_triggers.php --force');
        $this->line('     (or have a DBA create the two triggers from that migration)');
        Log::critical('Stock ledger immutability triggers check FAILED', ['problems' => $problems]);

        return self::FAILURE;
    }
}
