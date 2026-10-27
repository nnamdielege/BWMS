<?php

namespace App\Console\Commands;

use App\Integrations\Ordermentum\OrderInbox;
use App\Integrations\Ordermentum\OrdermentumClient;
use App\Integrations\Ordermentum\OrdermentumException;
use App\Models\Order;
use App\Models\SyncAttempt;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Safety net for missed webhooks (B3.2), every 10 minutes: reads recent orders
 * and queues any that are new or changed since we last applied them, using
 * updatedAt[gte] (verified on the live API 2026-10-27; results are not sorted).
 * The checkpoint only advances once every page was read, so a backlog larger
 * than the page cap is picked up by the next runs instead of skipped.
 */
class PollOrdermentumOrders extends Command
{
    protected $signature = 'channels:poll-ordermentum
        {--pages=10}
        {--since= : Catch-up from this date-time (business time zone), e.g. at cutover; default: last poll - 15 min}';

    protected $description = 'Queue Ordermentum orders that are new or changed since they were last applied';

    private const LAST_POLL_KEY = 'ordermentum:last_poll';

    public function handle(OrdermentumClient $client, OrderInbox $inbox): int
    {
        if (! config('bwms.ordermentum.ingest_enabled')) {
            $this->line('Ingestion is switched off.');

            return self::SUCCESS;
        }

        $startedAt = now();
        $since = $this->option('since')
            ? Carbon::parse($this->option('since'), config('bwms.business_timezone'))
            : Carbon::parse(Cache::get(self::LAST_POLL_KEY, now()->subDay()->toIso8601String()))->subMinutes(15);
        $queued = 0;

        $complete = false;
        for ($page = 1; $page <= (int) $this->option('pages'); $page++) {
            try {
                $response = $client->get('/v2/orders', [
                    'supplierId' => config('services.ordermentum.supplier_id'),
                    'pageNo' => $page,
                    'pageSize' => 100,
                    'updatedAt[gte]' => $since->toIso8601String(),
                ]);
            } catch (OrdermentumException $e) {
                $this->record(null, $e->getMessage());
                $this->error($e->getMessage());

                return self::FAILURE;
            }
            if (! $response->successful()) {
                $this->record($response->status(), "HTTP {$response->status()}");
                $this->error("Ordermentum returned HTTP {$response->status()}.");

                return self::FAILURE;
            }
            $this->record($response->status(), null);

            $orders = (array) ($response->json('data') ?? []);
            $known = Order::where('channel', 'ordermentum')->whereIn('external_ref', array_column($orders, 'id'))->pluck('external_version', 'external_ref');
            foreach ($orders as $o) {
                $version = isset($o['updatedAt']) ? (int) Carbon::parse($o['updatedAt'])->getPreciseTimestamp(3) : null;
                if (isset($known[$o['id']]) && $version !== null && $version <= $known[$o['id']]) {
                    continue;
                }
                if ($inbox->record('poll', (string) $o['id'], $o['updatedAt'] ?? null, json_encode($o))) {
                    $queued++;
                }
            }

            if (! $response->json('links.next') || $orders === []) {
                $complete = true;
                break;
            }
        }

        if ($complete) {
            Cache::forever(self::LAST_POLL_KEY, $startedAt->toIso8601String());
        } else {
            $this->warn('More orders changed than the page cap; the checkpoint was not advanced.');
        }
        $this->info("Queued {$queued} order(s).");

        return self::SUCCESS;
    }

    private function record(?int $status, ?string $error): void
    {
        SyncAttempt::create(['channel' => 'ordermentum', 'operation' => 'poll', 'response_code' => $status, 'success' => $error === null, 'error' => $error]);
    }
}
