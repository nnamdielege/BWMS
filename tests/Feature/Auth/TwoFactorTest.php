<?php

namespace Tests\Feature\Auth;

use App\Authorization\Permissions;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'JBSWY3DPEHPK3PXP';

    private function code(string $secret = self::SECRET): string
    {
        return app(Google2FA::class)->getCurrentOtp($secret);
    }

    private function confirmedPassword(): array
    {
        return ['auth.password_confirmed_at' => time()];
    }

    public function test_admin_without_2fa_is_sent_to_setup_and_blocked_from_everything_else()
    {
        $admin = $this->adminWithoutTwoFactor();

        $this->actingAs($admin)->get('/dashboard')->assertRedirect(route('two-factor.show'));
        $this->actingAs($admin)->get(route('admin.users.index'))->assertRedirect(route('two-factor.show'));
        $this->actingAs($admin)->get(route('two-factor.show'))->assertOk();
        $this->actingAs($admin)->get(route('profile.edit'))->assertOk();
    }

    public function test_non_admins_are_not_forced()
    {
        $this->actingAs($this->userWithRole(Permissions::ROLE_WAREHOUSE_STAFF))->get('/dashboard')->assertOk();
    }

    public function test_admin_can_enable_and_confirm_2fa_then_reach_the_app()
    {
        $admin = $this->adminWithoutTwoFactor();

        $this->actingAs($admin)->withSession($this->confirmedPassword())->post(route('two-factor.enable'))->assertRedirect();
        $admin->refresh();
        $this->assertNotNull($admin->two_factor_secret);
        $this->assertNull($admin->two_factor_confirmed_at);

        $secret = decrypt($admin->two_factor_secret);
        $this->actingAs($admin)->withSession($this->confirmedPassword())
            ->post(route('two-factor.confirm'), ['code' => '000000'])->assertSessionHasErrorsIn('confirmTwoFactorAuthentication', 'code');

        $this->actingAs($admin)->withSession($this->confirmedPassword())
            ->post(route('two-factor.confirm'), ['code' => $this->code($secret)])->assertSessionHasNoErrors();

        $this->assertNotNull($admin->fresh()->two_factor_confirmed_at);
        $this->assertCount(8, $admin->fresh()->recoveryCodes());
        $this->actingAs($admin->fresh())->get('/dashboard')->assertOk();
        $this->assertDatabaseHas('audit_logs', ['event' => 'two_factor_confirmed', 'user_id' => $admin->id]);
    }

    public function test_enabling_requires_a_recent_password_confirmation()
    {
        $admin = $this->adminWithoutTwoFactor();

        $this->actingAs($admin)->post(route('two-factor.enable'))->assertRedirect(route('password.confirm'));
        $this->assertNull($admin->fresh()->two_factor_secret);
    }

    public function test_login_with_2fa_requires_the_challenge()
    {
        $user = $this->userWithRole(Permissions::ROLE_ADMIN); // has 2FA (factory)

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('two-factor.login'));
        $this->assertGuest();
        $this->get(route('two-factor.login'))->assertOk();

        $this->post(route('two-factor.login.store'), ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertGuest();

        $this->post(route('two-factor.login.store'), ['code' => $this->code()])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('audit_logs', ['event' => 'login', 'user_id' => $user->id]);
    }

    public function test_recovery_code_works_once()
    {
        $user = $this->userWithRole(Permissions::ROLE_ADMIN);

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->post(route('two-factor.login.store'), ['recovery_code' => 'recovery-code-one'])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->assertNotContains('recovery-code-one', $user->fresh()->recoveryCodes());

        auth()->logout();
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->post(route('two-factor.login.store'), ['recovery_code' => 'recovery-code-one'])->assertSessionHasErrors('recovery_code');
        $this->assertGuest();
    }

    public function test_challenge_page_needs_a_pending_login()
    {
        $this->get(route('two-factor.login'))->assertRedirect(route('login'));
    }

    public function test_password_only_users_still_log_in_directly()
    {
        $user = $this->userWithRole(Permissions::ROLE_WAREHOUSE_STAFF);

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
    }

    public function test_admins_cannot_disable_but_others_can()
    {
        $admin = $this->userWithRole(Permissions::ROLE_ADMIN);
        $staff = User::factory()->withTwoFactor()->create()->assignRole(Permissions::ROLE_WAREHOUSE_STAFF);

        $this->actingAs($admin)->withSession($this->confirmedPassword())->delete(route('two-factor.disable'));
        $this->assertNotNull($admin->fresh()->two_factor_confirmed_at);

        $this->actingAs($staff)->withSession($this->confirmedPassword())->delete(route('two-factor.disable'));
        $this->assertNull($staff->fresh()->two_factor_secret);
    }

    public function test_admin_can_reset_another_users_2fa_but_not_their_own()
    {
        $admin = $this->userWithRole(Permissions::ROLE_ADMIN);
        $other = $this->userWithRole(Permissions::ROLE_ADMIN);

        $this->actingAs($admin)->delete(route('admin.users.reset-two-factor', $admin))->assertForbidden();
        $this->actingAs($admin)->delete(route('admin.users.reset-two-factor', $other))->assertRedirect();

        $this->assertNull($other->fresh()->two_factor_secret);
        $this->actingAs($other->fresh())->get('/dashboard')->assertRedirect(route('two-factor.show'));
        $this->assertDatabaseHas('audit_logs', ['event' => 'two_factor_reset_by_admin', 'auditable_id' => $other->id]);
    }

    public function test_secrets_never_reach_the_audit_log_or_shared_props()
    {
        $admin = $this->adminWithoutTwoFactor();
        $this->actingAs($admin)->withSession($this->confirmedPassword())->post(route('two-factor.enable'));

        $this->assertDatabaseMissing('audit_logs', ['new_values' => null, 'event' => 'updated', 'auditable_id' => $admin->id]);
        foreach (AuditLog::all() as $log) {
            $this->assertStringNotContainsString('two_factor_secret', json_encode($log->new_values ?? []));
        }
        $this->assertArrayNotHasKey('two_factor_secret', $admin->fresh()->toArray());
    }
}
