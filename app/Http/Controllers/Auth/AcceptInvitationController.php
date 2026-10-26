<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserInvitation;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class AcceptInvitationController extends Controller
{
    public function show(string $token): Response
    {
        $invitation = UserInvitation::findPendingByToken($token);
        abort_unless($invitation, 404, 'This invitation is invalid or has expired.');

        return Inertia::render('auth/AcceptInvitation', [
            'token' => $token,
            'email' => $invitation->email,
            'name' => $invitation->name,
        ]);
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user = DB::transaction(function () use ($token, $data) {
            $invitation = UserInvitation::pending()
                ->where('token_hash', hash('sha256', $token))
                ->lockForUpdate()
                ->first();

            abort_unless($invitation, 404, 'This invitation is invalid or has expired.');
            abort_if(User::where('email', $invitation->email)->exists(), 409, 'An account with this email already exists.');

            $user = User::create([
                'name' => $data['name'],
                'email' => $invitation->email,
                'password' => $data['password'],
            ]);
            $user->forceFill(['email_verified_at' => now()])->saveQuietly();
            $user->assignRole($invitation->role);

            $invitation->forceFill(['accepted_at' => now(), 'accepted_user_id' => $user->id])->save();
            Audit::record('invitation_accepted', $invitation, new: ['role' => $invitation->role], userId: $user->id);

            return $user;
        });

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }
}
