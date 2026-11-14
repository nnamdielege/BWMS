<?php

namespace App\Console\Commands;

use App\Integrations\Ordermentum\OrdermentumClient;
use App\Integrations\Ordermentum\OrdermentumException;
use App\Integrations\Ordermentum\OrdermentumOrderMapper;
use App\Integrations\Ordermentum\UnmappableOrder;
use App\Models\ChannelListing;
use App\Models\SalesHistory;
use App\Support\BusinessDate;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;

/**
 * One-off (re-runnable): Ordermentum sales from before BWMS into sales_history, for
 * reorder suggestions. Read-only on Ordermentum. NEVER creates orders or reservations:
 * it only writes sales_history.
 *
 * Takes orders delivered from N months ago up to the day before go-live
 * (ORDERMENTUM_INGEST_SINCE), so nothing BWMS dispatched itself is counted twice.
 * Statuses in ORDERMENTUM_HISTORY_STATUSES only; cancelled never. One row per order
 * line, keyed by order and line, so a re-run updates rather than adds, and an order
 * cancelled since is taken out. Lines for variants not mapped to a product are counted
 * and skipped: map them on Sync and run it again.
 */
class ImportOrdermentumHistory extends Command
{
    protected $signature = 'channels:ordermentum-history
        {--months=12 : How far back}
        {--pages=1000 : Page cap (100 orders a page)}
        {--dry-run : Read and count, write nothing}';

    protected $description = 'Import historical Ordermentum sales into sales_history (read-only on Ordermentum; never creates orders)';

    public function handle(OrdermentumClient $client, OrdermentumOrderMapper $mapper): int
    {
        $tz = config('bwms.business_timezone');
        $today = BusinessDate::today();
        $from = Carbon::parse($today, $tz)->subMonthsNoOverflow((int) $this->option('months'))->toDateString();
        $until = config('bwms.ordermentum.ingest_since') ?: $today; // exclusive
        $statuses = array_map('strtolower', config('bwms.ordermentum.history_statuses'));
        $dry = (bool) $this->option('dry-run');
        $this->info("Ordermentum orders delivered {$from} to the day before {$until}".($dry ? ' (dry run: nothing is written)' : '').'.');

        $variants = ChannelListing::where('channel', 'ordermentum')->with('unit:id,base_qty')->get()->keyBy('external_variant_id');
        $stats = ['read' => 0, 'imported' => 0, 'lines' => 0, 'cancelled' => 0, 'out_of_range' => 0, 'unmapped_lines' => 0, 'removed' => 0];
        $statusSeen = [];
        $unmapped = [];
        $rows = [];

        // Orders delivered in the window were updated after (window start − 60 days), whatever happened since.
        $updatedSince = Carbon::parse($from, $tz)->subDays(60)->utc()->toIso8601String();
        for ($page = 1; $page <= (int) $this->option('pages'); $page++) {
            try {
                $response = $this->get($client, '/v2/orders', ['supplierId' => config('services.ordermentum.supplier_id'), 'pageNo' => $page, 'pageSize' => 100, 'updatedAt[gte]' => $updatedSince]);
            } catch (OrdermentumException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
            if (! $response->successful()) {
                $this->error("Ordermentum returned HTTP {$response->status()} on page {$page}; nothing written.");

                return self::FAILURE;
            }
            $orders = (array) ($response->json('data') ?? []);

            foreach ($orders as $order) {
                $stats['read']++;
                $id = (string) ($order['id'] ?? '');
                $status = (string) ($order['status'] ?? '');
                $statusSeen[$status] = ($statusSeen[$status] ?? 0) + 1;
                $date = $mapper->deliveryDate($order);
                if ($id === '' || $date === null || $date < $from || $date >= $until) {
                    $stats['out_of_range']++;

                    continue;
                }
                if (filled($order['cancelledAt'] ?? null) || ! in_array(strtolower($status), $statuses, true)) {
                    $stats['cancelled']++;
                    $rows[$id] = []; // nothing sold: take out anything an earlier run imported

                    continue;
                }

                $mapped = $this->lines($client, $mapper, $order);
                if ($mapped === null) {
                    $this->warn("Order {$id}: lines could not be read; skipped.");

                    continue;
                }
                $rows[$id] = [];
                foreach ($mapped as $line) {
                    $listing = $variants[$line['variant_id']] ?? null;
                    if (! $listing || $line['quantity'] <= 0) {
                        if (! $listing) {
                            $stats['unmapped_lines']++;
                            $unmapped[$line['variant_id']] = $line['sku'] ?? $line['name'] ?? '';
                        }

                        continue;
                    }
                    $rows[$id][] = [
                        'source' => SalesHistory::SOURCE_ORDERMENTUM, 'product_id' => $listing->product_id, 'sale_date' => $date,
                        'qty_base' => $line['quantity'] * max(1, (int) $listing->unit?->base_qty), 'external_ref' => $id, 'line_ref' => $line['line_ref'],
                    ];
                }
                $stats['imported'] += $rows[$id] !== [] ? 1 : 0;
                $stats['lines'] += count($rows[$id]);
            }

            if (! $response->json('links.next') || $orders === []) {
                break;
            }
            if ($page === (int) $this->option('pages')) {
                $this->warn('Stopped at the page cap; run again with a higher --pages to read the rest.');
            }
        }

        $all = array_merge(...array_values($rows ?: [[]]));
        if (! $dry) {
            DB::transaction(function () use ($rows, $all, &$stats) {
                $now = now();
                // Lines no longer on an order (or the order no longer counts) come out first.
                foreach (array_chunk(array_keys($rows), 500) as $ids) {
                    $keep = collect($all)->whereIn('external_ref', $ids)->map(fn ($r) => $r['external_ref'].'|'.$r['line_ref'])->all();
                    $stats['removed'] += SalesHistory::where('source', SalesHistory::SOURCE_ORDERMENTUM)->whereIn('external_ref', $ids)->get(['id', 'external_ref', 'line_ref'])
                        ->reject(fn ($h) => in_array($h->external_ref.'|'.$h->line_ref, $keep, true))
                        ->each(fn ($h) => SalesHistory::whereKey($h->id)->delete())->count();
                }
                foreach (array_chunk($all, 500) as $chunk) {
                    SalesHistory::upsert(array_map(fn ($r) => $r + ['created_at' => $now, 'updated_at' => $now], $chunk),
                        ['source', 'external_ref', 'line_ref'], ['product_id', 'sale_date', 'qty_base', 'updated_at']);
                }
            });
        }

        $products = count(array_unique(array_column($all, 'product_id')));
        $days = count(array_unique(array_column($all, 'sale_date')));
        $dates = array_column($all, 'sale_date');
        $this->table(['', ''], [
            ['Orders read', $stats['read']],
            ['Orders imported', $stats['imported']],
            ['Order lines imported', $stats['lines']],
            ['Products', $products],
            ['Days with sales', $days],
            ['First / last day', $dates ? min($dates).' / '.max($dates) : '—'],
            ['Not sold (cancelled or other status)', $stats['cancelled']],
            ['Delivered outside the window', $stats['out_of_range']],
            ['Lines for unmapped variants (skipped)', $stats['unmapped_lines'].' ('.count($unmapped).' variants)'],
            ['Rows taken out (no longer sold)', $stats['removed']],
        ]);
        $this->line('Statuses seen: '.collect($statusSeen)->map(fn ($n, $s) => ($s ?: '(none)')." {$n}")->implode(', '));
        if ($unmapped !== []) {
            $this->line('Unmapped variants: '.collect($unmapped)->map(fn ($label, $v) => trim("{$v} {$label}"))->take(20)->implode('; ').(count($unmapped) > 20 ? '; …' : ''));
        }

        return self::SUCCESS;
    }

    private int $fetched = 0;

    /**
     * A GET that waits out rate limits instead of giving up: the client sleeps through short
     * waits itself and throws for longer ones (the order endpoint allows 24 a minute, and the
     * list leaves lines out, so a year of orders takes a while). Anything else is thrown.
     */
    private function get(OrdermentumClient $client, string $path, array $query): Response
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $client->get($path, $query);
            } catch (OrdermentumException $e) {
                if ($e->status !== 429 || $attempt >= 30) {
                    throw $e;
                }
                Sleep::for(max(1, $e->retryAfter ?? 60))->seconds();
            }
        }
    }

    /** The order's lines: from the list when it carries them, else from the order itself. @return list<array>|null */
    private function lines(OrdermentumClient $client, OrdermentumOrderMapper $mapper, array $order): ?array
    {
        try {
            return $mapper->map($order)['lines'];
        } catch (UnmappableOrder) {
            // The list may leave lines out: fetch the order.
        }
        try {
            $response = $this->get($client, '/v1/orders/'.rawurlencode((string) $order['id']), ['includeLines' => 'true']);
        } catch (OrdermentumException) {
            return null;
        }
        if (++$this->fetched % 100 === 0) {
            $this->line("… {$this->fetched} orders fetched one by one (Ordermentum allows 24 a minute).");
        }
        if (! $response->successful()) {
            return null;
        }
        try {
            return $mapper->map((array) $response->json())['lines'];
        } catch (UnmappableOrder) {
            return null;
        }
    }
}
