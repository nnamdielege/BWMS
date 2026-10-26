<?php

namespace App\Http\Controllers\Settings;

use App\Authorization\Permissions;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;

/**
 * The 2FA settings page and the "disable" action. Enable / confirm / QR /
 * recovery codes use Fortify's own controllers (see routes/settings.php).
 */
class TwoFactorController extends Controller
{
    public function show(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('settings/TwoFactor', [
            'enabled' => (bool) $user->two_factor_secret,
            'confirmed' => (bool) $user->two_factor_confirmed_at,
            'required' => $user->hasRole(Permissions::ROLE_ADMIN),
            'status' => $request->session()->get('status'),
        ]);
    }

    public function destroy(Request $request, DisableTwoFactorAuthentication $disable): RedirectResponse
    {
        $user = $request->user();

        if ($user->hasRole(Permissions::ROLE_ADMIN) && $user->two_factor_confirmed_at) {
            return back()->with('error', 'Administrators cannot turn off two-factor authentication. Regenerate recovery codes instead, or ask another admin to reset it.');
        }

        $disable($user);

        return back()->with('success', 'Two-factor authentication turned off.');
    }
}
