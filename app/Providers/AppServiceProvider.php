<?php

namespace App\Providers;

use App\Events\OrderOversold;
use App\Integrations\Ordermentum\OrdermentumClient;
use App\Support\Audit;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(OrdermentumClient::class, fn () => new OrdermentumClient(config('services.ordermentum')));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Every completed sign-in (password-only, or after the 2FA challenge).
        Event::listen(Login::class, function (Login $event) {
            $event->user->forceFill(['last_login_at' => now()])->saveQuietly();
            Audit::record('login', $event->user, userId: $event->user->getAuthIdentifier());
        });

        // D-3 alert. The dashboard lists flagged orders; email/Slack alerts are Phase 2 (B6).
        Event::listen(OrderOversold::class, fn (OrderOversold $e) => Log::warning('Order accepted beyond available stock', ['order_id' => $e->orderId, 'skus' => $e->skus]));
    }
}
