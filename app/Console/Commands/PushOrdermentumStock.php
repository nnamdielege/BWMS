<?php

namespace App\Console\Commands;

use App\Integrations\Ordermentum\ChannelRetryableError;
use App\Integrations\Ordermentum\OrdermentumException;
use App\Integrations\Ordermentum\StockPushService;
use App\Jobs\PushOrdermentumStock as PushJob;
use App\Jobs\ReconcileChannelListing;
use App\Models\ChannelListing;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Sleep;

/**
 * push: queue a forced push for every enabled listing (cutover, or after an outage).
 * reconcile: nightly check of every enabled listing; mismatches are re-pushed (D-9).
 * Both only queue jobs; the jobs are rate-limited, and both need ORDERMENTUM_PUSH_ENABLED.
 *
 * push-once: a reviewed, one-off push that works with the switch off. --dry-run shows each
 * listing's figure; without it (and with --yes) those figures are sent once, one PUT each,
 * logged to sync_attempts and recorded as last pushed. Ongoing push stays as it is.
 */
class PushOrdermentumStock extends Command
{
    protected $signature = 'channels:ordermentum {action : push, reconcile or push-once}
        {--sku=* : push-once: only these SKUs}
        {--only-zero-pushed : push-once: only listings last pushed as 0}
        {--dry-run : push-once: show what would be sent, send nothing}
        {--yes : push-once: send without asking (required to send)}';

    protected $description = 'Queue a full Ordermentum stock push or the nightly reconciliation, or send a reviewed one-off push (push-once)';

    public function handle(StockPushService $pusher): int
    {
        if ($this->argument('action') === 'push-once') {
            return $this->pushOnce($pusher);
        }
        if (! config('bwms.ordermentum.push_enabled')) {
            $this->line('Stock push is switched off (ORDERMENTUM_PUSH_ENABLED). For a reviewed one-off push, use push-once.');

            return self::SUCCESS;
        }

        $listings = ChannelListing::where('channel', 'ordermentum')->where('sync_enabled', true);

        return match ($this->argument('action')) {
            'push' => $this->queued(tap($listings->distinct()->pluck('product_id'), fn ($ids) => PushJob::request($ids, force: true))->count(), 'product push'),
            'reconcile' => $this->queued($listings->pluck('id')->each(fn ($id) => ReconcileChannelListing::dispatch($id))->count(), 'check'),
            default => tap(self::FAILURE, fn () => $this->error('Action must be push, reconcile or push-once.')),
        };
    }

    private function pushOnce(StockPushService $pusher): int
    {
        $dry = (bool) $this->option('dry-run');
        if (! $dry && ! $this->option('yes')) {
            $this->error('Review the figures with --dry-run first, then send them with --yes.');

            return self::FAILURE;
        }

        $listings = $this->listings();
        if ($listings->isEmpty()) {
            $this->warn('No enabled Ordermentum listings match.');

            return self::SUCCESS;
        }
        $held = $pusher->heldVariants();
        $plan = $listings->map(fn (ChannelListing $l) => [
            'listing' => $l,
            'available' => intdiv(max(0, $pusher->available($l)), max(1, $l->unit->base_qty)),
            'send' => $pusher->expectedQty($l),
            'held' => $held[$l->external_variant_id] ?? null,
        ]);

        $this->table(['SKU', 'Pack', 'WMS available', 'Buffer', 'Last pushed', 'Last pushed at', $dry ? 'Would send' : 'Sending'], $plan->map(fn ($p) => [
            $p['listing']->product->sku,
            $p['listing']->unit->name.($p['listing']->unit->base_qty > 1 ? " ({$p['listing']->unit->base_qty})" : ''),
            $p['available'],
            $p['listing']->safety_buffer ?: '',
            $p['listing']->last_pushed_qty ?? '—',
            $p['listing']->last_pushed_at?->setTimezone(config('bwms.business_timezone'))->format('Y-m-d H:i') ?? '—',
            $p['held'] ? "held: order {$p['held']} needs mapping" : $p['send'],
        ])->all());
        $toSend = $plan->whereNull('held')->values();
        $this->line($toSend->count().' listing(s) to send'.($plan->count() > $toSend->count() ? ', '.($plan->count() - $toSend->count()).' held' : '').'.');

        if ($dry) {
            $this->info('Dry run: nothing sent. Send these figures with --yes.');

            return self::SUCCESS;
        }
        if ($pusher->ingestionPending() > 0) {
            $this->error('Ordermentum order events are still being processed. Sending now could overwrite a decrement BWMS has not seen yet; try again in a minute.');

            return self::FAILURE;
        }

        $sent = $failed = 0;
        foreach ($toSend as $p) {
            if ($this->send($pusher, $p['listing'], $p['send'])) {
                $sent++;
            } else {
                $failed++;
                $this->warn("{$p['listing']->product->sku}: ".($p['listing']->fresh()->last_error ?? 'not sent'));
            }
        }
        $this->info("Sent {$sent}, failed {$failed}. Ongoing push is unchanged (ORDERMENTUM_PUSH_ENABLED=".(config('bwms.ordermentum.push_enabled') ? 'true' : 'false').').');

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** One PUT, waiting out the rate limit rather than giving up. False on a 4xx or 5xx (already logged). */
    private function send(StockPushService $pusher, ChannelListing $listing, int $qty): bool
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                $pusher->pushOnce($listing, $qty);

                return $listing->fresh()->last_error === null;
            } catch (ChannelRetryableError) {
                return false;
            } catch (OrdermentumException $e) {
                if ($e->status !== 429 || $attempt >= 10) {
                    $this->warn("{$listing->product->sku}: {$e->getMessage()}");

                    return false;
                }
                Sleep::for(max(1, $e->retryAfter ?? 60))->seconds();
            }
        }
    }

    /** @return Collection<int, ChannelListing> enabled listings of products sold on Ordermentum, as the ongoing push would take them */
    private function listings(): Collection
    {
        return ChannelListing::where('channel', 'ordermentum')->where('sync_enabled', true)
            ->whereHas('product', fn ($q) => $q->where('is_stock_tracked', true)->whereIn('sell_channels', Product::channelsIncluding('ordermentum')))
            ->when($this->option('sku'), fn ($q, $skus) => $q->whereHas('product', fn ($p) => $p->whereIn('sku', array_map('strtoupper', $skus))))
            ->when($this->option('only-zero-pushed'), fn ($q) => $q->where('last_pushed_qty', 0))
            ->with(['product', 'unit'])->get()->sortBy('product.sku')->values();
    }

    private function queued(int $count, string $what): int
    {
        $this->info("Queued {$count} {$what} job(s).");

        return self::SUCCESS;
    }
}
