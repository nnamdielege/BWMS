<?php

namespace Tests\Feature;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

/**
 * Guardrail (NFR-7): every route must be either explicitly public, or require
 * authentication AND carry an authorization check (`can:` middleware).
 * A new route that forgets authorization fails this test.
 */
class RouteAuthorizationTest extends TestCase
{
    /** Routes reachable without logging in. Each needs its own justification. */
    private const PUBLIC = [
        'home',                 // redirect only
        'login', 'password.request', 'password.email', 'password.reset', 'password.store',
        'invitations.show', 'invitations.accept',
        'two-factor.login', 'two-factor.login.store', // challenge after password; requires session login.id
        'storage.local',        // framework local-disk route (signed URLs only)
        'webhooks.ordermentum', // secret path token (Ordermentum has no signing); only stores and queues
    ];

    /** Authenticated routes any active user may use (own account / landing page). */
    private const ANY_AUTHENTICATED = [
        'dashboard', 'logout', 'password.confirm', 'password.confirm.store',
        'settings', 'profile.edit', 'profile.update', 'password.edit', 'password.update', 'appearance',
        // Own 2FA management (password-confirmed). Admin-only restrictions are enforced in the controller.
        'two-factor.show', 'two-factor.enable', 'two-factor.confirm', 'two-factor.disable',
        'two-factor.qr-code', 'two-factor.secret-key', 'two-factor.recovery-codes', 'two-factor.regenerate-recovery-codes',
    ];

    /** Own-account routes deliberately outside the admin 2FA gate (so admins can set it up). */
    private const OUTSIDE_ADMIN_2FA_GATE = [
        'logout', 'password.confirm', 'password.confirm.store',
        'settings', 'profile.edit', 'profile.update', 'password.edit', 'password.update', 'appearance',
        'two-factor.show', 'two-factor.enable', 'two-factor.confirm', 'two-factor.disable',
        'two-factor.qr-code', 'two-factor.secret-key', 'two-factor.recovery-codes', 'two-factor.regenerate-recovery-codes',
    ];

    public function test_every_business_route_is_behind_the_admin_2fa_gate()
    {
        $missing = collect(RouteFacade::getRoutes())
            ->filter(fn (Route $r) => in_array('auth', $r->gatherMiddleware(), true))
            ->reject(fn (Route $r) => in_array($r->getName(), self::OUTSIDE_ADMIN_2FA_GATE, true))
            ->reject(fn (Route $r) => in_array('2fa.admin', $r->gatherMiddleware(), true))
            ->map(fn (Route $r) => $r->getName() ?? $r->uri())->values()->all();

        $this->assertSame([], $missing, 'Routes not behind 2fa.admin: '.implode(', ', $missing));
    }

    public function test_every_route_is_public_by_allowlist_or_authenticated_and_authorized()
    {
        $offenders = [];

        foreach (RouteFacade::getRoutes() as $route) {
            /** @var Route $route */
            $name = $route->getName() ?? $route->uri();
            $middleware = $route->gatherMiddleware();

            if ($this->isFrameworkRoute($route) || in_array($name, self::PUBLIC, true)) {
                continue;
            }

            if (! in_array('auth', $middleware, true)) {
                $offenders[] = "{$name}: not authenticated and not in PUBLIC allowlist";

                continue;
            }

            if (in_array($name, self::ANY_AUTHENTICATED, true)) {
                continue;
            }

            $hasCan = collect($middleware)->contains(fn ($m) => is_string($m) && str_starts_with($m, 'can:'));
            if (! $hasCan) {
                $offenders[] = "{$name}: authenticated but has no can: authorization";
            }
        }

        $this->assertSame([], $offenders, "Routes missing authorization:\n".implode("\n", $offenders));
    }

    public function test_authenticated_routes_require_an_active_account()
    {
        $missing = collect(RouteFacade::getRoutes())
            ->filter(fn (Route $r) => in_array('auth', $r->gatherMiddleware(), true))
            // Logout and password confirmation must work even for a just-deactivated session.
            ->reject(fn (Route $r) => in_array($r->getName(), ['logout', 'password.confirm', 'password.confirm.store'], true))
            ->reject(fn (Route $r) => in_array('active', $r->gatherMiddleware(), true))
            ->map(fn (Route $r) => $r->getName() ?? $r->uri())
            ->values()
            ->all();

        $this->assertSame([], $missing, 'Authenticated routes without the active middleware: '.implode(', ', $missing));
    }

    private function isFrameworkRoute(Route $route): bool
    {
        return in_array($route->uri(), ['up', 'sanctum/csrf-cookie'], true)
            || str_starts_with($route->uri(), '_ignition')
            || str_starts_with($route->uri(), 'storage/');
    }
}
