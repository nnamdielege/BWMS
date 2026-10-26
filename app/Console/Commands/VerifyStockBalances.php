<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * NFR-9: asserts the balance projection equals SUM(ledger) for every slot.
 * Scheduled nightly; exits non-zero and logs an error on drift.
 */
class VerifyStockBalances extends Command
{
    protected $signature = 'stock:verify';

    protected $description = 'Check stock_balances against the stock_movements ledger';

    public function handle(): int
    {
        $drift = self::findDrift();

        if ($drift === []) {
            $this->info('Stock balances match the ledger.');

            return self::SUCCESS;
        }

        $this->error(count($drift).' balance(s) differ from the ledger:');
        $this->table(['product_id', 'location_id', 'batch_key', 'balance', 'ledger'], $drift);
        Log::error('Stock balance drift detected', ['count' => count($drift), 'sample' => array_slice($drift, 0, 20)]);

        return self::FAILURE;
    }

    /** @return list<array{product_id:int, location_id:int, batch_key:int, balance:int, ledger:int}> */
    public static function findDrift(): array
    {
        $ledger = DB::table('stock_movements')
            ->selectRaw('product_id, location_id, COALESCE(batch_id, 0) as batch_key, SUM(qty) as qty')
            ->groupBy('product_id', 'location_id', DB::raw('COALESCE(batch_id, 0)'))
            ->get()
            ->keyBy(fn ($r) => "{$r->product_id}-{$r->location_id}-{$r->batch_key}");

        $balances = DB::table('stock_balances')->get(['product_id', 'location_id', 'batch_key', 'on_hand'])
            ->keyBy(fn ($r) => "{$r->product_id}-{$r->location_id}-{$r->batch_key}");

        $drift = [];
        foreach ($ledger->keys()->merge($balances->keys())->unique() as $key) {
            $l = (int) ($ledger[$key]->qty ?? 0);
            $b = (int) ($balances[$key]->on_hand ?? 0);
            if ($l !== $b) {
                [$p, $loc, $bk] = array_map('intval', explode('-', $key));
                $drift[] = ['product_id' => $p, 'location_id' => $loc, 'batch_key' => $bk, 'balance' => $b, 'ledger' => $l];
            }
        }

        return $drift;
    }
}
