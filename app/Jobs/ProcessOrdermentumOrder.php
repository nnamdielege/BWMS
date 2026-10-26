<?php

namespace App\Jobs;

use App\Integrations\Ordermentum\OrderIngestor;
use App\Integrations\Ordermentum\OrdermentumException;
use App\Models\IntegrationInbox;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Fetches one Ordermentum order and applies it (NFR-5: processed async, never
 * in the webhook request). One queued job per order; an update arriving while
 * it runs queues the next run, and runs for one order never overlap.
 */
class ProcessOrdermentumOrder implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 60; // queue retry_after (90s) must exceed this

    public int $maxExceptions = 5;

    public int $uniqueFor = 600;

    /** @var list<int> */
    public array $backoff = [30, 60, 120, 300, 600];

    public function __construct(public string $orderId, public ?int $inboxId = null) {}

    public function uniqueId(): string
    {
        return $this->orderId;
    }

    public function retryUntil(): Carbon
    {
        return now()->addHours(12);
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('om-order:'.$this->orderId))->releaseAfter(10)->expireAfter(180),
        ];
    }

    public function handle(OrderIngestor $ingestor): void
    {
        if (! config('bwms.ordermentum.ingest_enabled')) {
            IntegrationInbox::find($this->inboxId)?->mark(IntegrationInbox::STATUS_HELD, 'Ingestion is switched off (ORDERMENTUM_INGEST_ENABLED).');

            return;
        }

        try {
            $ingestor->ingest($this->orderId, $this->inboxId ? IntegrationInbox::find($this->inboxId) : null);
        } catch (OrdermentumException $e) {
            if ($e->retryAfter !== null) {
                $this->release($e->retryAfter);

                return;
            }
            throw $e;
        }
    }

    public function failed(Throwable $e): void
    {
        IntegrationInbox::find($this->inboxId)?->mark(IntegrationInbox::STATUS_FAILED, mb_strimwidth($e->getMessage(), 0, 1000, '...'));
    }
}
