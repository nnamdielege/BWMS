<?php

namespace App\Jobs;

use App\Enums\OrderChannel;
use App\Integrations\Portal\PortalClient;
use App\Integrations\Portal\PortalUnavailable;
use App\Models\Order;
use App\Models\PickList;
use App\Models\PickListLine;
use App\Models\SyncAttempt;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;

/**
 * Dispatch notification to the portal (spec B4.4 order-events): what left
 * the warehouse on one pick list for one portal order. "short_shipped" when
 * less was picked than planned. Idempotent on the portal side by
 * order_ref + event + at; the Idempotency-Key is stable per pick list.
 */
class SendPortalOrderEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 30;

    public int $maxExceptions = 10;

    /** @var list<int> */
    public array $backoff = [30, 60, 300, 900, 3600];

    public function __construct(public int $orderId, public int $pickListId) {}

    public function retryUntil(): Carbon
    {
        return now()->addDay();
    }

    public static function payload(Order $order, PickList $pickList): array
    {
        $lines = PickListLine::where('pick_list_id', $pickList->id)
            ->whereIn('order_line_id', $order->lines()->pluck('id'))
            ->get()->groupBy('order_line_id');

        $short = false;
        $out = [];
        foreach ($order->lines as $line) {
            if (! isset($lines[$line->id])) {
                continue;
            }
            $picked = (int) $lines[$line->id]->sum('qty_picked_base');
            $short = $short || $picked < (int) $lines[$line->id]->sum('qty_to_pick_base');
            $per = max(1, $line->base_qty_per_unit);
            $out[] = ['line_ref' => $line->line_ref, 'qty' => $picked % $per === 0 ? intdiv($picked, $per) : round($picked / $per, 3)];
        }

        return [
            'order_ref' => $order->external_ref,
            'event' => $short ? 'short_shipped' : 'dispatched',
            'lines' => $out,
            'at' => $pickList->dispatched_at->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    public function handle(PortalClient $client): void
    {
        if (! config('bwms.portal.events_enabled')) {
            return;
        }
        $order = Order::with('lines')->find($this->orderId);
        $pickList = PickList::find($this->pickListId);
        if (! $order || ! $pickList || $order->channel !== OrderChannel::Portal || ! $pickList->dispatched_at) {
            return;
        }

        $payload = self::payload($order, $pickList);
        $started = hrtime(true);
        try {
            $response = $client->postOrderEvent($payload, "bwms:order-event:{$order->external_ref}:{$pickList->id}");
        } catch (PortalUnavailable $e) {
            $this->record($order, $payload, null, $started, $e->getMessage());
            throw $e;
        }
        $error = $response->successful() ? null : "HTTP {$response->status()}: ".mb_strimwidth($response->body(), 0, 300, '...');
        $this->record($order, $payload, $response->status(), $started, $error);

        if ($response->serverError()) {
            throw new PortalUnavailable($error);
        }
    }

    private function record(Order $order, array $payload, ?int $status, int $started, ?string $error): void
    {
        SyncAttempt::create([
            'channel' => 'portal', 'operation' => 'order_event', 'external_id' => mb_substr((string) $order->external_ref, 0, 64),
            'request' => $payload, 'response_code' => $status, 'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
            'success' => $error === null, 'error' => $error,
        ]);
    }
}
