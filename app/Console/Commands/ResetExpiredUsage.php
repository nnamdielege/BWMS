<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Services\UsageTrackingService;
use Illuminate\Console\Command;

class ResetExpiredUsage extends Command
{
    protected $signature = 'usage:reset-expired';
    protected $description = 'Reset usage for subscriptions with expired billing cycles';

    protected $usageService;

    public function __construct(UsageTrackingService $usageService)
    {
        parent::__construct();
        $this->usageService = $usageService;
    }

    public function handle()
    {
        $subscriptions = Subscription::where('status', '!=', 'cancelled')->get();

        $count = 0;
        foreach ($subscriptions as $subscription) {
            if ($this->usageService->resetExpiredUsage($subscription->id)) {
                $count++;
                $this->info("✅ Reset usage for subscription {$subscription->id}");
            }
        }

        $this->info("✅ Completed! Reset usage for {$count} subscriptions");
    }
}