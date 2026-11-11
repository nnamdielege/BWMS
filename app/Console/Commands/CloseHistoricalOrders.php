<?php

namespace App\Console\Commands;

use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Listeners\QueueChannelStockPush;
use App\Models\Order;
use App\Services\Orders\OrderService;
use App\Support\Audit;
use App\Support\BusinessDate;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * One-off clean-up: open Ordermentum orders delivered before go-live were
 * imported in error (Ordermentum re-sent them when their updatedAt changed).
 * Each is cancelled through the normal cancel, which releases its reservation
 * and moves no stock. Nothing is sent to any channel: no cancel notice and no
 * stock push (run a push or the daily reconcile when you choose). One audit
 * entry per order.
 *
 * Also lists open Ordermentum orders delivered from the cutoff up to yesterday
 * and never picked, to be decided by hand. Those are never touched.
 */
class CloseHistoricalOrders extends Command
{
    public const REASON = 'Historical order imported in error';

    protected $signature = 'orders:close-historical
        {--before= : Cancel open Ordermentum orders delivered before this date (Y-m-d), e.g. 2026-11-06}
        {--dry-run : List what would be cancelled, change nothing}';

    protected $description = 'Cancel open Ordermentum orders delivered before go-live (imported in error)';

    public function handle(OrderService $orders): int
    {
        $before = (string) $this->option('before');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $before) || BusinessDate::parse($before) !== $before) {
            $this->error('--before=YYYY-MM-DD is required, e.g. --before=2026-11-06.');

            return self::INVALID;
        }
        $dryRun = (bool) $this->option('dry-run');
        $today = BusinessDate::today();

        $historical = $this->openOrdermentum()->where('delivery_date', '<', $before)->get();
        $this->info(sprintf('%d open Ordermentum order(s) delivered before %s%s:', $historical->count(), $before, $dryRun ? ' would be cancelled' : ''));
        $this->listOrders($historical);

        $cancelled = 0;
        $failed = [];
        if (! $dryRun) {
            // One audit entry per order (the cancel's own), no stock push to any channel.
            QueueChannelStockPush::muted(function () use ($historical, $orders, &$cancelled, &$failed) {
                Audit::withoutModelEvents(function () use ($historical, $orders, &$cancelled, &$failed) {
                    foreach ($historical as $order) {
                        try {
                            $orders->cancel($order, null, self::REASON); // no actor: no cancel notice to the channel
                            $cancelled++;
                        } catch (Throwable $e) {
                            $failed[] = "{$order->number}: {$e->getMessage()}";
                        }
                    }
                });
            });
        }

        $pending = $this->openOrdermentum()->where('delivery_date', '>=', $before)->where('delivery_date', '<', $today)->get();
        $this->newLine();
        $this->info(sprintf('%d open Ordermentum order(s) delivered %s to %s and never picked (not touched; decide by hand):',
            $pending->count(), $before, Carbon::parse($today)->subDay()->toDateString()));
        $this->listOrders($pending);

        $this->newLine();
        foreach ($failed as $line) {
            $this->error("Not cancelled: {$line}");
        }
        if ($dryRun) {
            $this->info('Dry run: nothing changed.');
        } else {
            $this->info("Cancelled {$cancelled} order(s); reservations released, no stock moved, nothing sent to any channel.");
            if ($cancelled > 0) {
                $this->line('Channel stock figures were not pushed. When ready: php artisan channels:ordermentum reconcile');
            }
        }

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }

    /** Open = reserved and not on a pick list. */
    private function openOrdermentum(): Builder
    {
        return Order::query()->where('channel', OrderChannel::Ordermentum)->where('status', OrderStatus::Open)
            ->with(['customer', 'lines.product', 'lines.unit'])->orderBy('delivery_date')->orderBy('id');
    }

    /** @param  Collection<int, Order>  $orders */
    private function listOrders(Collection $orders): void
    {
        if ($orders->isEmpty()) {
            $this->line('  (none)');

            return;
        }
        $this->table(['Order', 'Customer', 'Delivery', 'Lines'], $orders->map(fn (Order $o) => [
            $o->number,
            $o->customer?->name ?? '-',
            $o->delivery_date?->toDateString() ?? '-',
            $o->lines->map(fn ($l) => "{$l->product?->sku} ×{$l->qty}".($l->unit ? " {$l->unit->name}" : ''))->implode(', '),
        ])->all());
    }
}
