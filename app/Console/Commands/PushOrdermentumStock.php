<?php

namespace App\Console\Commands;

use App\Jobs\PushOrdermentumStock as PushJob;
use App\Jobs\ReconcileChannelListing;
use App\Models\ChannelListing;
use Illuminate\Console\Command;

/**
 * push: queue a forced push for every enabled listing (cutover, or after an outage).
 * reconcile: nightly check of every enabled listing; mismatches are re-pushed (D-9).
 * Both only queue jobs; the jobs are rate-limited.
 */
class PushOrdermentumStock extends Command
{
    protected $signature = 'channels:ordermentum {action : push or reconcile}';

    protected $description = 'Queue a full Ordermentum stock push, or the nightly reconciliation';

    public function handle(): int
    {
        if (! config('bwms.ordermentum.push_enabled')) {
            $this->line('Stock push is switched off (ORDERMENTUM_PUSH_ENABLED).');

            return self::SUCCESS;
        }

        $listings = ChannelListing::where('channel', 'ordermentum')->where('sync_enabled', true);

        return match ($this->argument('action')) {
            'push' => $this->queued(tap($listings->distinct()->pluck('product_id'), fn ($ids) => PushJob::request($ids, force: true))->count(), 'product push'),
            'reconcile' => $this->queued($listings->pluck('id')->each(fn ($id) => ReconcileChannelListing::dispatch($id))->count(), 'check'),
            default => tap(self::FAILURE, fn () => $this->error('Action must be push or reconcile.')),
        };
    }

    private function queued(int $count, string $what): int
    {
        $this->info("Queued {$count} {$what} job(s).");

        return self::SUCCESS;
    }
}
