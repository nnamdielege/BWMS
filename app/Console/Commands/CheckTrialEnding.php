<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Mail\TrialEndingSoonMail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Carbon\Carbon;

class CheckTrialEnding extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'subscriptions:check-trial-ending';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check for trials ending soon and send notification emails';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Checking for trials ending soon...');

        // Get subscriptions with trials ending in 3 days
        $threeDaysFromNow = Carbon::now()->addDays(3)->startOfDay();
        $endOfThreeDays = Carbon::now()->addDays(3)->endOfDay();

        $subscriptions = Subscription::where('status', 'trialing')
            ->whereNotNull('trial_ends_at')
            ->whereBetween('trial_ends_at', [$threeDaysFromNow, $endOfThreeDays])
            ->with(['user', 'plan'])
            ->get();

        $sentCount = 0;

        foreach ($subscriptions as $subscription) {
            try {
                // Check if we've already sent a notification (avoid duplicate sends)
                $lastNotificationKey = 'trial_ending_notified_' . $subscription->id;

                if (!cache()->has($lastNotificationKey)) {
                    Mail::to($subscription->user->email)->send(
                        new TrialEndingSoonMail($subscription)
                    );

                    // Cache for 7 days to prevent duplicate sends
                    cache()->put($lastNotificationKey, true, now()->addDays(7));

                    $sentCount++;
                    $this->info("Sent trial ending notification to: {$subscription->user->email}");
                }
            } catch (\Exception $e) {
                $this->error("Failed to send email to {$subscription->user->email}: {$e->getMessage()}");
            }
        }

        $this->info("Total notifications sent: {$sentCount}");
        return 0;
    }
}