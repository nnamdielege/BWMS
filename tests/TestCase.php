<?php

namespace Tests;

use App\Authorization\Permissions;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Spatie\Permission\Models\Role;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Inertia responses don't need compiled assets under test.
        $this->withoutVite();
    }

    /**
     * Create an active user holding the given role(s). Seeds roles on first use.
     * Admins get confirmed 2FA (required by D-8) unless $adminTwoFactor is false.
     */
    protected function userWithRole(string ...$roles): User
    {
        return $this->makeUser($roles, true);
    }

    /** An admin who has not set up 2FA yet. */
    protected function adminWithoutTwoFactor(): User
    {
        return $this->makeUser([Permissions::ROLE_ADMIN], false);
    }

    /** @param  list<string>  $roles */
    private function makeUser(array $roles, bool $adminTwoFactor): User
    {
        if (Role::count() === 0) {
            $this->seed(RolesAndPermissionsSeeder::class);
        }

        $factory = User::factory();
        if ($adminTwoFactor && in_array(Permissions::ROLE_ADMIN, $roles, true)) {
            $factory = $factory->withTwoFactor();
        }

        return $factory->create()->assignRole($roles);
    }
}
