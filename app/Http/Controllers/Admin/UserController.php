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

    public function setActive(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);

        $user->forceFill(['is_active' => $data['is_active']])->save();

        return back()->with('success', $data['is_active'] ? "{$user->name} re-activated." : "{$user->name} deactivated.");
    }
}
