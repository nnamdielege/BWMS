<?php

use App\Models\WebhookEvent;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule your low-stock command here
Schedule::command('inventory:check-low-stock')->dailyAt('09:00');

// Retry failed webhooks every hour
Schedule::command('webhooks:retry')->hourly()->withoutOverlapping();

// Check for trials ending soon (run daily at 9 AM)
Schedule::command('subscriptions:check-trial-ending')->dailyAt('09:00')->withoutOverlapping();

// Cleanup old processed webhooks (monthly)
Schedule::call(
    function () {
        WebhookEvent::where('status', 'processed')
            ->where('created_at', '<', now()->subDays(30))
            ->delete();
    }
)->monthly()->at('02:00');

// Reset expired usage daily at 2 AM
Schedule::command('usage:reset-expired')->dailyAt('02:00');

//Sync every 30 minutes (more frequent)
Schedule::command('sync:stock')->everyThirtyMinutes();
