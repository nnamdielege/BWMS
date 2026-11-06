<?php

namespace App\Console\Commands;

use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Integrations\Ordermentum\OrdermentumClient;
use App\Integrations\Ordermentum\OrdermentumException;
use App\Integrations\Ordermentum\OrdermentumOrderMapper;
use App\Integrations\Ordermentum\UnmappableOrder;
use App\Models\Order;
use App\Services\Delivery\DeliveryScheduler;
use App\Support\Audit;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;

/**
 * Puts open orders not yet on a pick list on the date their source gave.
 * Ordermentum dates are read again from the API (GET only); the portal and manual
 * orders use the date already stored. An order on a run set by hand stays put.
 */
class BackfillDeliveryDates extends Command
{
    protected $signature = 'orders:backfill-delivery-dates
        {--dry-run : Show each order and what would move; save nothing}
        {--yes : Apply the moves. Required without --dry-run}';

    protected $description = 'Move open orders not yet on a pick list onto their source delivery date';

    public function handle(DeliveryScheduler $scheduler, OrdermentumClient $client, OrdermentumOrderMapper $mapper): int
    {
        if (! $this->option('dry-run') && ! $this->option('yes')) {
            $this->error('Nothing done. Add --dry-run to see what would move, or --yes to apply.');

            return self::FAILURE;
        }

        $orders = Order::with('deliveryRun.area')->whereIn('status', [OrderStatus::Open, OrderStatus::PartiallyDispatched])->orderBy('id')->get()
            ->reject(fn (Order $o) => $scheduler->onPickList($o));

        $rows = [];
        $counts = ['checked' => 0, 'move' => 0, 'same' => 0, 'kept' => 0, 'unread' => 0];
        foreach ($orders as $order) {
            $counts['checked']++;
            $current = $order->delivery_date?->toDateString();
            try {
                $source = $this->sourceDate($order, $client, $mapper);
            } catch (OrdermentumException|UnmappableOrder $e) {
                $counts['unread']++;
                $rows[] = [$order->number, $order->channel->label(), $current ?? '-', '?', '-', "Could not read: {$e->getMessage()}"];

                continue;
            }

            $target = $scheduler->dateFor($order, $source);
            $action = match (true) {
                $order->run_assignment === 'manual' => 'kept',
                $target === $current && ($order->delivery_run_id || ! $scheduler->areaForOrder($order)) => 'same',
                default => 'move',
            };
            $counts[$action]++;
            $rows[] = [$order->number, $order->channel->label(), $current ?? '-', $source ?? 'none', $target ?? '-',
                ['move' => 'Moves', 'same' => 'No change', 'kept' => 'Run set by hand: stays'][$action]];

            if ($action === 'move' && ! $this->option('dry-run')) {
                DB::transaction(function () use ($order, $source, $current, $scheduler) {
                    $order->forceFill(['requested_delivery_date' => $source])->save();
                    $scheduler->assign($order->refresh(), dateChanged: true);
                    Audit::record('order_delivery_date_backfilled', $order, ['delivery_date' => $current], ['delivery_date' => $order->fresh()->delivery_date?->toDateString()], ['source_date' => $source]);
                });
            }
        }

        if ($rows !== []) {
            $this->table(['Order', 'Source', 'Current', 'Source date', 'Would be', 'Action'], $rows);
        }
        $this->table(['Checked', 'Would move', 'No change', 'Kept (set by hand)', 'Could not read'], [[$counts['checked'], $counts['move'], $counts['same'], $counts['kept'], $counts['unread']]]);
        $this->option('dry-run') ? $this->warn('Dry run: nothing was saved.') : $this->info("Moved {$counts['move']} order(s).");

        return self::SUCCESS;
    }

    private function sourceDate(Order $order, OrdermentumClient $client, OrdermentumOrderMapper $mapper): ?string
    {
        if ($order->channel !== OrderChannel::Ordermentum) {
            return $order->requested_delivery_date?->toDateString();
        }

        $response = $this->waitingOutLimits(fn () => $client->get('/v1/orders/'.rawurlencode((string) $order->external_ref)));
        if (! $response->successful()) {
            throw new OrdermentumException("HTTP {$response->status()}", $response->status());
        }

        return $mapper->deliveryDate((array) $response->json());
    }

    /** One fetch per order runs past the client's budget on any busy day, so a CLI run waits it out like om:probe does. */
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
