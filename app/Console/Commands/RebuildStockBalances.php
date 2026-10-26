<?php

namespace App\Console\Commands;

use App\Support\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Recomputes stock_balances from the ledger. The ledger is the truth; this
 * only repairs the projection. Takes a table lock, so run it in a quiet window.
 */
class RebuildStockBalances extends Command
{
    protected $signature = 'stock:rebuild-balances {--force : Skip confirmation}';

    protected $description = 'Rebuild stock_balances from stock_movements';

    public function handle(): int
    {
        if (! $this->option('force') && ! $this->confirm('Rebuild all stock balances from the ledger?')) {
            return self::FAILURE;
        }

        $before = count(VerifyStockBalances::findDrift());

        DB::transaction(function () {
            // Serialise against StockLedger writers for the duration.
            DB::table('stock_balances')->lockForUpdate()->get(['id']);

            DB::table('stock_balances')->update(['on_hand' => 0, 'updated_at' => now()]);

            DB::table('stock_movements')
                ->selectRaw('product_id, location_id, batch_id, COALESCE(batch_id, 0) as batch_key, SUM(qty) as qty')
                ->groupBy('product_id', 'location_id', 'batch_id')
                ->orderBy('product_id')
                ->each(function ($row) {
                    DB::table('stock_balances')->upsert(
                        [[
                            'product_id' => $row->product_id,
                            'location_id' => $row->location_id,
                            'batch_id' => $row->batch_id,
                            'batch_key' => $row->batch_key,
                            'on_hand' => (int) $row->qty,
                            'updated_at' => now(),
                        ]],
                        ['product_id', 'location_id', 'batch_key'],
                        ['on_hand', 'updated_at'],
                    );
                });
        });

        Audit::record('stock_balances_rebuilt', meta: ['drifted_slots_before' => $before]);
        $this->info("Rebuilt. Slots that had drifted: {$before}.");

        return self::SUCCESS;
    }
}
