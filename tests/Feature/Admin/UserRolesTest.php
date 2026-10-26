<?php

namespace Tests\Feature\Admin;

use App\Authorization\Permissions;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserRolesTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_change_another_users_roles_and_it_is_audited()
    {
        $admin = $this->userWithRole(Permissions::ROLE_ADMIN);
        $staff = $this->userWithRole(Permissions::ROLE_WAREHOUSE_STAFF);

        $this->actingAs($admin)
            ->put(route('admin.users.roles', $staff), ['roles' => ['purchasing']])
            ->assertSessionHasNoErrors();

        $this->assertSame(['purchasing'], $staff->fresh()->getRoleNames()->all());
        $this->assertDatabaseHas('audit_logs', ['event' => 'roles_changed', 'auditable_id' => $staff->id, 'user_id' => $admin->id]);
    }

    public function test_nobody_can_change_their_own_roles()
    {
        $admin = $this->userWithRole(Permissions::ROLE_ADMIN);

        $this->actingAs($admin)
            ->put(route('admin.users.roles', $admin), ['roles' => ['read_only']])
            ->assertForbidden();

        $this->assertTrue($admin->fresh()->hasRole('admin'));
    }

    public function test_a_non_admin_with_users_manage_cannot_grant_admin_or_touch_admins()
    {
        // A custom role with users.manage but not admin, to prove the policy rule
        // holds even if the matrix changes later.
        $this->userWithRole(Permissions::ROLE_READ_ONLY);
        Role::findOrCreate('user_manager', 'web')->givePermissionTo([Permissions::USERS_VIEW, Permissions::USERS_MANAGE]);
        $manager = User::factory()->create()->assignRole('user_manager');
        $staff = $this->userWithRole(Permissions::ROLE_WAREHOUSE_STAFF);
        $admin = $this->userWithRole(Permissions::ROLE_ADMIN);

        $this->actingAs($manager)->put(route('admin.users.roles', $staff), ['roles' => ['admin']])->assertForbidden();
        $this->actingAs($manager)->put(route('admin.users.roles', $admin), ['roles' => ['read_only']])->assertForbidden();
        $this->actingAs($manager)->put(route('admin.users.roles', $staff), ['roles' => ['purchasing']])->assertSessionHasNoErrors();

        $this->assertTrue($admin->fresh()->hasRole('admin'));
        $this->assertSame(['purchasing'], $staff->fresh()->getRoleNames()->all());
    }

    public function test_user_without_users_manage_cannot_change_roles()
    {
        $staff = $this->userWithRole(Permissions::ROLE_WAREHOUSE_STAFF);
        $other = $this->userWithRole(Permissions::ROLE_READ_ONLY);

        $this->actingAs($staff)->put(route('admin.users.roles', $other), ['roles' => ['admin']])->assertForbidden();
        $this->actingAs($staff)->get(route('admin.users.index'))->assertForbidden();
    }

    public function test_cannot_deactivate_yourself_and_deactivated_users_cannot_log_in()
    {
        $admin = $this->userWithRole(Permissions::ROLE_ADMIN);
        $staff = $this->userWithRole(Permissions::ROLE_WAREHOUSE_STAFF);

        $this->actingAs($admin)->put(route('admin.users.active', $admin), ['is_active' => false])->assertForbidden();
        $this->actingAs($admin)->put(route('admin.users.active', $staff), ['is_active' => false])->assertSessionHasNoErrors();
        auth()->logout();

        $this->post('/login', ['email' => $staff->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_role_and_active_flags_are_not_mass_assignable_via_profile()
    {
        $staff = $this->userWithRole(Permissions::ROLE_WAREHOUSE_STAFF);

        $this->actingAs($staff)->patch(route('profile.update'), ['name' => 'New', 'email' => 'evil@example.com', 'is_active' => false, 'roles' => ['admin']]);

        $staff->refresh();
        $this->assertSame('New', $staff->name);
        $this->assertNotSame('evil@example.com', $staff->email);
        $this->assertTrue($staff->is_active);
        $this->assertFalse($staff->hasRole('admin'));
    }
}
