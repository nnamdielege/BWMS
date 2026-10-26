<?php

namespace App\Http\Controllers\Admin;

use App\Authorization\Permissions;
use App\Http\Controllers\Controller;
use App\Mail\UserInvitationMail;
use App\Models\User;
use App\Models\UserInvitation;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

class InvitationController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255', Rule::unique(User::class, 'email')],
            'name' => ['required', 'string', 'max:255'],
            'role' => ['required', Rule::in(Permissions::roles())],
        ]);

        Gate::authorize('inviteWithRole', [UserInvitation::class, $data['role']]);

        // Supersede any earlier pending invitation for the same address.
        UserInvitation::pending()->where('email', strtolower($data['email']))
            ->each(fn (UserInvitation $old) => $old->forceFill(['revoked_at' => now()])->save());

        [$invitation, $token] = UserInvitation::issue($data['email'], $data['name'], $data['role'], $request->user());
        Mail::to($invitation->email)->queue(new UserInvitationMail($invitation, $token));
        Audit::record('invitation_sent', $invitation, new: ['email' => $invitation->email, 'role' => $invitation->role]);

        return back()->with('success', "Invitation sent to {$invitation->email}.");
    }

    public function resend(Request $request, UserInvitation $invitation): RedirectResponse
    {
        abort_if($invitation->accepted_at || $invitation->revoked_at, 422, 'Invitation is no longer pending.');
        Gate::authorize('inviteWithRole', [UserInvitation::class, $invitation->role]);

        $token = $invitation->rotateToken();
        Mail::to($invitation->email)->queue(new UserInvitationMail($invitation, $token));
        Audit::record('invitation_resent', $invitation);

        return back()->with('success', "Invitation re-sent to {$invitation->email}.");
    }

    public function destroy(UserInvitation $invitation): RedirectResponse
    {
        abort_if($invitation->accepted_at, 422, 'Invitation has already been accepted.');
        $invitation->forceFill(['revoked_at' => now()])->save();

        return back()->with('success', 'Invitation revoked.');
    }
}
