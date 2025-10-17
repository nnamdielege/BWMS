<?php

namespace App\Console\Commands;

use App\Services\StripeWebhookService;
use Illuminate\Console\Command;

class RetryFailedWebhooks extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'webhooks:retry';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Retry failed webhook processing';

    /**
     * Execute the console command.
     */
    public function handle(StripeWebhookService $webhookService)
    {
        $this->info('Starting webhook retry process...');

        try {
            $webhookService->retryFailedWebhooks();
            $this->info('Webhook retry process completed successfully');
            return 0;
        } catch (\Exception $e) {
            $this->error('Webhook retry failed: ' . $e->getMessage());
            return 1;
        }
    }
}