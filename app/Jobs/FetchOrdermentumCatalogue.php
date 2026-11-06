<?php

namespace App\Jobs;

use App\Integrations\Ordermentum\OrdermentumCatalogue;
use App\Integrations\Ordermentum\OrdermentumException;
use App\Services\Catalogue\Import\ImportPreviewStore;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/** Reads the Ordermentum catalogue for the import preview. No API call happens in a request (NFR-2). */
class FetchOrdermentumCatalogue implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 75; // queue retry_after (90s) must exceed this

    public int $tries = 1;

    public function __construct(public int $userId, public string $token) {}

    public function handle(OrdermentumCatalogue $catalogue, ImportPreviewStore $store): void
    {
        try {
            $store->ready($this->userId, $this->token, 'ordermentum', $catalogue->items());
        } catch (OrdermentumException $e) {
            $store->failed($this->userId, $this->token, 'ordermentum', $e->getMessage());
        }
    }

    public function failed(Throwable $e): void
    {
        app(ImportPreviewStore::class)->failed($this->userId, $this->token, 'ordermentum', 'The fetch did not finish. Try again.');
    }
}
