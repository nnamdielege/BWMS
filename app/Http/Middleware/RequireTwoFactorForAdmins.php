<?php

namespace App\Http\Middleware;

use App\Authorization\Permissions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * D-8: an admin without confirmed 2FA can reach nothing but their own
 * settings (where they enable it) until they have set it up.
 */
class RequireTwoFactorForAdmins
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->two_factor_confirmed_at && $user->hasRole(Permissions::ROLE_ADMIN)) {
            if ($request->expectsJson()) {
                abort(403, 'Two-factor authentication is required for administrators.');
            }

            return redirect()->route('two-factor.show')
                ->with('error', 'Administrators must set up two-factor authentication before continuing.');
        }

        return $next($request);
    }
}
