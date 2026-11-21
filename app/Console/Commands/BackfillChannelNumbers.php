<?php

namespace App\Console\Commands;

use App\Enums\OrderChannel;
use App\Integrations\Ordermentum\OrderIngestor;
use App\Integrations\Ordermentum\OrdermentumClient;
use App\Integrations\Ordermentum\OrdermentumException;
use App\Integrations\Ordermentum\OrdermentumOrderMapper;
use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Sleep;

/**
 * Fills Ordermentum invoice and order numbers on orders ingested before they were kept, so
 * Income allocation can match bank receipts to them. Reads each order from the API (GET only).
 */
class BackfillChannelNumbers extends Command
{
    protected $signature = 'orders:backfill-channel-numbers
        {--days=120 : Orders created in this many days}
        {--dry-run : Show what would be filled; save nothing}
        {--yes : Save the numbers. Required without --dry-run}';

    protected $description = 'Fill Ordermentum invoice and order numbers on recent orders (for matching bank receipts)';

    public function handle(OrdermentumClient $client, OrdermentumOrderMapper $mapper): int
    {
        if (! $this->option('dry-run') && ! $this->option('yes')) {
            $this->error('Nothing done. Add --dry-run to see what would be filled, or --yes to save.');

            return self::FAILURE;
        }

        $orders = Order::where('channel', OrderChannel::Ordermentum)->whereNotNull('external_ref')
            ->whereNull('channel_invoice_number')->whereNull('channel_order_number')
            ->where('created_at', '>=', now()->subDays(max(1, (int) $this->option('days'))))->orderBy('id')->get();

        $rows = [];
        $counts = ['checked' => 0, 'filled' => 0, 'none' => 0, 'unread' => 0];
        foreach ($orders as $order) {
            $counts['checked']++;
            try {
                $response = $this->waitingOutLimits(fn () => $client->get('/v1/orders/'.rawurlencode((string) $order->external_ref)));
                if (! $response->successful()) {
                    throw new OrdermentumException("HTTP {$response->status()}", $response->status());
                }
                $numbers = $mapper->numbers((array) $response->json());
            } catch (OrdermentumException $e) {
                $counts['unread']++;
                $rows[] = [$order->number, '-', '-', "Could not read: {$e->getMessage()}"];

                continue;
            }
            if ($numbers['invoice_number'] === null && $numbers['order_number'] === null) {
                $counts['none']++;
                $rows[] = [$order->number, '-', '-', 'None on the order: check the field names with om:probe'];

                continue;
            }
            $counts['filled']++;
            $rows[] = [$order->number, $numbers['invoice_number'] ?? '-', $numbers['order_number'] ?? '-', $this->option('dry-run') ? 'Would fill' : 'Filled'];
            if (! $this->option('dry-run')) {
                OrderIngestor::rememberNumbers($order, $numbers);
            }
        }

        if ($rows !== []) {
            $this->table(['Order', 'Invoice number', 'Order number', 'Result'], $rows);
        }
        $this->table(['Checked', 'Filled', 'No numbers', 'Could not read'], [[$counts['checked'], $counts['filled'], $counts['none'], $counts['unread']]]);
        $this->option('dry-run') ? $this->warn('Dry run: nothing was saved.') : $this->info("Filled {$counts['filled']} order(s).");

        return self::SUCCESS;
    }

    private function waitingOutLimits(callable $call): Response
    {
        for ($i = 0; ; $i++) {
            try {
                return $call();
            } catch (OrdermentumException $e) {
                if ($e->retryAfter === null || $i >= 5) {
                    throw $e;
                }
                $this->line("  rate limit: waiting {$e->retryAfter}s");
                Sleep::for($e->retryAfter)->seconds();
            }
        }
    }
}
