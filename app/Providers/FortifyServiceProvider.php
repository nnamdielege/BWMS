<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // We register only the two-factor endpoints ourselves.
        Fortify::ignoreRoutes();
    }

    public function boot(): void
    {
        RateLimiter::for('two-factor', fn (Request $request) => Limit::perMinute(5)->by($request->session()->get('login.id').'|'.$request->ip()));

        $audit = fn (string $event) => fn ($e) => Audit::record($event, $e->user instanceof User ? $e->user : null, userId: $e->user->id ?? null);

        Event::listen(TwoFactorAuthenticationEnabled::class, $audit('two_factor_enabled'));
        Event::listen(TwoFactorAuthenticationConfirmed::class, $audit('two_factor_confirmed'));
        Event::listen(TwoFactorAuthenticationDisabled::class, $audit('two_factor_disabled'));
        Event::listen(RecoveryCodesGenerated::class, $audit('two_factor_recovery_codes_regenerated'));
        Event::listen(TwoFactorAuthenticationFailed::class, $audit('two_factor_failed'));
    }
}
