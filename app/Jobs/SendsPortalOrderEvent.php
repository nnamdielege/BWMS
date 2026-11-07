<?php

namespace App\Jobs;

use App\Integrations\Portal\PortalClient;
use App\Integrations\Portal\PortalUnavailable;
use App\Models\Order;
use App\Models\SyncAttempt;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;

/**
 * Telling the portal something happened to an order (spec B4.4 order-events).
 * 5xx and lost connections retry for a day, and every attempt is logged in
 * sync_attempts. A 4xx is logged and not retried. Idempotent on the portal side
 * through the Idempotency-Key.
 */
abstract class SendsPortalOrderEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 30;

    public int $maxExceptions = 10;

    /** @var list<int> */
    public array $backoff = [30, 60, 300, 900, 3600];

    public function retryUntil(): Carbon
    {
        return now()->addDay();
    }

    abstract protected function order(): ?Order;

    /** @return array<string, mixed>|null null when there is nothing to send */
    abstract protected function body(Order $order): ?array;

    abstract protected function idempotencyKey(Order $order): string;

    public function handle(PortalClient $client): void
    {
        if (! config('bwms.portal.events_enabled')) {
            return;
        }
        $order = $this->order();
        $payload = $order ? $this->body($order) : null;
        if ($payload === null) {
            return;
        }

        $started = hrtime(true);
        try {
            $response = $client->postOrderEvent($payload, $this->idempotencyKey($order));
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

    /** Sell units from base units: whole packs, or a fraction of one. */
    public static function packs(int $base, int $perPack): int|float
    {
        $per = max(1, $perPack);

        return $base % $per === 0 ? intdiv($base, $per) : round($base / $per, 3);
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
