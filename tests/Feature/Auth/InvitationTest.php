<?php

namespace Tests\Feature\Auth;

use App\Authorization\Permissions;
use App\Mail\UserInvitationMail;
use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class InvitationTest extends TestCase
{
    use RefreshDatabase;

    public function test_there_is_no_public_registration()
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', ['email' => 'x@example.com'])->assertNotFound();
    }

    public function test_admin_can_invite_and_mail_is_queued()
    {
        Mail::fake();
        $admin = $this->userWithRole(Permissions::ROLE_ADMIN);

        $this->actingAs($admin)
            ->post(route('admin.invitations.store'), ['name' => 'Sam', 'email' => 'Sam@Example.com', 'role' => 'warehouse_staff'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('user_invitations', ['email' => 'sam@example.com', 'role' => 'warehouse_staff']);
        Mail::assertQueued(UserInvitationMail::class, fn ($m) => $m->hasTo('sam@example.com'));
    }

    public function test_invitee_can_accept_and_gets_the_invited_role()
    {
        $this->userWithRole(Permissions::ROLE_ADMIN);
        [$invitation, $token] = UserInvitation::issue('new@example.com', 'New', 'purchasing', null);

        $this->get(route('invitations.show', $token))->assertOk();
        $this->post(route('invitations.accept', $token), [
            'name' => 'New Person', 'password' => 'Str0ng-pass!', 'password_confirmation' => 'Str0ng-pass!',
        ])->assertRedirect(route('dashboard'));

        $user = User::where('email', 'new@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertTrue($user->hasRole('purchasing'));
        $this->assertNotNull($invitation->fresh()->accepted_at);
    }

    public function test_invitation_token_is_single_use()
    {
        $this->userWithRole(Permissions::ROLE_ADMIN);
        [, $token] = UserInvitation::issue('once@example.com', 'Once', 'read_only', null);
        $payload = ['name' => 'Once', 'password' => 'Str0ng-pass!', 'password_confirmation' => 'Str0ng-pass!'];

        $this->post(route('invitations.accept', $token), $payload)->assertRedirect();
        auth()->logout();

        $this->post(route('invitations.accept', $token), $payload)->assertNotFound();
        $this->assertSame(1, User::where('email', 'once@example.com')->count());
    }

    public function test_expired_and_revoked_invitations_are_rejected()
    {
        $this->userWithRole(Permissions::ROLE_ADMIN);
        [$expired, $t1] = UserInvitation::issue('a@example.com', 'A', 'read_only', null);
        $expired->forceFill(['expires_at' => now()->subMinute()])->save();
        [$revoked, $t2] = UserInvitation::issue('b@example.com', 'B', 'read_only', null);
        $revoked->forceFill(['revoked_at' => now()])->save();

        $this->get(route('invitations.show', $t1))->assertNotFound();
        $this->get(route('invitations.show', $t2))->assertNotFound();
    }

    public function test_only_the_hash_of_the_token_is_stored()
    {
        [$invitation, $token] = UserInvitation::issue('h@example.com', 'H', 'read_only', null);

        $this->assertSame(hash('sha256', $token), $invitation->token_hash);
        $this->assertDatabaseMissing('user_invitations', ['token_hash' => $token]);
    }

    public function test_non_admin_manager_cannot_invite_an_admin()
    {
        Mail::fake();
        $manager = $this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER);
        // Warehouse managers have users.view but not users.invite in the default matrix.
        $this->actingAs($manager)
            ->post(route('admin.invitations.store'), ['name' => 'X', 'email' => 'x@example.com', 'role' => 'admin'])
            ->assertForbidden();

        Mail::assertNothingQueued();
    }
}
