<?php

namespace Tests;

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

    /** Create an active user holding the given role(s). Seeds roles on first use. */
    protected function userWithRole(string ...$roles): User
    {
        if (Role::count() === 0) {
            $this->seed(RolesAndPermissionsSeeder::class);
        }

        return User::factory()->create()->assignRole($roles);
    }
}
