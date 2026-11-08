<?php

namespace App\Console\Commands;

use App\Services\Orders\OversoldCheck;
use Illuminate\Console\Command;

/**
 * One-off: clears oversold flags left from before they re-checked themselves.
 * The same check runs on every stock change from now on.
 */
class RecheckOversold extends Command
{
    protected $signature = 'orders:recheck-oversold {--dry-run : List what would be cleared, change nothing}';

    protected $description = 'Clear the oversold flag on open orders that stock now covers';

    public function handle(OversoldCheck $check): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $results = $check->recheck(dryRun: $dryRun, trigger: 'orders:recheck-oversold');

        $rows = array_map(fn (array $r) => [
            $r['order']->number,
            $r['order']->oversold_at?->setTimezone(config('bwms.business_timezone'))->format('Y-m-d H:i'),
            $r['short'] === [] ? ($dryRun ? 'would clear' : 'cleared') : 'still short',
            implode(', ', $r['short']),
        ], $results);
        $this->table(['Order', 'Flagged', 'Result', 'Short SKUs'], $rows);

        $covered = count(array_filter($results, fn ($r) => $r['short'] === []));
        $this->info(sprintf('%d flagged open order(s): %d %s, %d still short.%s',
            count($results), $covered, $dryRun ? 'would be cleared' : 'cleared', count($results) - $covered, $dryRun ? ' Dry run: nothing changed.' : ''));

        return self::SUCCESS;
    }
}
