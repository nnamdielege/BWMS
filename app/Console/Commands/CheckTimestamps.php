<?php

namespace App\Console\Commands;

use App\Models\UserInvitation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Before 2026_11_08_090000, five TIMESTAMP columns rewrote themselves to "now"
 * whenever their row was updated (MariaDB, explicit_defaults_for_timestamp=0).
 * An overwritten value equals updated_at, which differs from created_at. This
 * lists those rows and what they should probably be. Read-only: it changes
 * nothing, and prints the UPDATEs for a person to check and run.
 */
class CheckTimestamps extends Command
{
    protected $signature = 'bwms:check-timestamps';

    protected $description = 'List rows whose timestamp was likely overwritten by ON UPDATE, with suggested repairs (read-only)';

    /** table => [column, what it should be, why] */
    private const CHECKS = [
        'stocktakes' => ['snapshot_at', 'created_at', 'the snapshot is taken when the stocktake is created'],
        'receipts' => ['received_at', 'created_at', 'a receipt is received when it is created'],
        'order_packs' => ['packed_at', 'created_at', 'the pack row is created when the order is packed'],
        'user_invitations' => ['expires_at', 'created_at + '.UserInvitation::TTL_DAYS.' days', 'invitations last '.UserInvitation::TTL_DAYS.' days from sending (or from the last resend)'],
    ];

    public function handle(): int
    {
        $this->line('Read-only. Nothing is changed.');
        $statements = [];

        foreach (self::CHECKS as $table => [$column, $suggest, $why]) {
            $rows = DB::table($table)
                ->whereColumn($column, 'updated_at')
                ->whereColumn('updated_at', '!=', 'created_at')
                ->orderBy('id')
                ->get(['id', $column, 'created_at', 'updated_at']);

            $this->newLine();
            $this->info("{$table}.{$column}: {$rows->count()} likely overwritten. Suggested: {$suggest} ({$why}).");
            if ($rows->isEmpty()) {
                continue;
            }
            $this->table(['id', "{$column} now", 'created_at', 'updated_at', 'suggested'], $rows->map(fn ($r) => [
                $r->id, $r->{$column}, $r->created_at, $r->updated_at, $this->suggested($table, $r->created_at),
            ])->all());
            foreach ($rows as $r) {
                $statements[] = "UPDATE `{$table}` SET `{$column}` = '{$this->suggested($table, $r->created_at)}' WHERE id = {$r->id} AND `{$column}` = '{$r->{$column}}';";
            }
        }

        $this->newLine();
        $this->info('stock_movements.occurred_at: no updated_at to compare, so rows can\'t be checked this way.');
        $this->line($this->ledgerProtected()
            ? 'The stock_movements_no_update trigger is installed, so no movement can have been updated or overwritten.'
            : 'WARNING: the stock_movements_no_update trigger is missing (see bwms:check-triggers), so movements could have been updated and occurred_at overwritten.');

        if ($statements !== []) {
            $this->newLine();
            $this->info('Suggested repairs (not run). Check them, then run them yourself:');
            foreach ($statements as $sql) {
                $this->line($sql);
            }
        }

        return self::SUCCESS;
    }

    private function suggested(string $table, string $createdAt): string
    {
        return $table === 'user_invitations'
            ? date('Y-m-d H:i:s', strtotime($createdAt.' +'.UserInvitation::TTL_DAYS.' days'))
            : $createdAt;
    }

    private function ledgerProtected(): bool
    {
        return DB::table('information_schema.triggers')
            ->where('trigger_schema', DB::getDatabaseName())
            ->where('trigger_name', 'stock_movements_no_update')
            ->exists();
    }
}
