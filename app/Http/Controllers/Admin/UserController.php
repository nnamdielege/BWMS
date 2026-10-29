<?php

namespace App\Http\Controllers\Admin;

use App\Authorization\Permissions;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserInvitation;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;

class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $actor = $request->user();

        return Inertia::render('admin/users/Index', [
            'users' => User::with('roles:id,name')->orderBy('name')->get()
                ->map(fn (User $u) => [
                    'id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'is_active' => $u->is_active,
                    'last_login_at' => $u->last_login_at?->toIso8601String(),
                    'two_factor' => (bool) $u->two_factor_confirmed_at,
                    'roles' => $u->roles->pluck('name'),
                    'can_manage' => $actor->can('updateRoles', $u),
                ]),
            'invitations' => UserInvitation::pending()->with('inviter:id,name')->latest()->get()
                ->map(fn (UserInvitation $i) => [
                    'id' => $i->id,
                    'email' => $i->email,
                    'name' => $i->name,
                    'role' => $i->role,
                    'expires_at' => $i->expires_at->toIso8601String(),
                    'invited_by' => $i->inviter?->name,
                ]),
            'roles' => Permissions::roles(),
            'canInvite' => $actor->can('create', UserInvitation::class),
            'canGrantAdmin' => $actor->hasRole(Permissions::ROLE_ADMIN),
        ]);
    }

    public function updateRoles(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'roles' => ['present', 'array'],
            'roles.*' => ['string', Rule::in(Permissions::roles())],
        ]);

        $roles = array_values(array_unique($data['roles']));
        Gate::authorize('canGrantRoles', [$user, $roles]);

        $before = $user->getRoleNames()->all();
        $user->syncRoles($roles);
        Audit::record('roles_changed', $user, ['roles' => $before], ['roles' => $roles]);

        return back()->with('success', "Roles updated for {$user->name}.");
    }

    /** Lost-device recovery: clears another user's 2FA so they set it up again. */
    public function resetTwoFactor(User $user, DisableTwoFactorAuthentication $disable): RedirectResponse
    {
        $disable($user);
        Audit::record('two_factor_reset_by_admin', $user);

        return back()->with('success', "Two-factor reset for {$user->name}. They must set it up again".($user->hasRole(Permissions::ROLE_ADMIN) ? ' before continuing.' : '.'));
    }

    public function setActive(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);

        $user->forceFill(['is_active' => $data['is_active']])->save();

        return back()->with('success', $data['is_active'] ? "{$user->name} re-activated." : "{$user->name} deactivated.");
    }

    /**
     * Admin-only email change (users can't change their own). Saved quietly and
     * audited as email_changed. No re-verification: the app has no email
     * verification step; an address is proven once, by accepting the invitation.
     */
    public function updateEmail(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
        ]);
        $old = $user->email;
        if ($data['email'] === $old) {
            return back();
        }

        $user->forceFill(['email' => $data['email']])->saveQuietly();
        Audit::record('email_changed', $user, ['email' => $old], ['email' => $data['email']]);

        return back()->with('success', "Email for {$user->name} changed to {$data['email']}. They log in with the new address from now on.");
    }
}
