<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed reference data only (idempotent, safe on every deploy). The first
     * admin is created with `php artisan bwms:invite-admin`, never with a
     * default password.
     */
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            DefaultSiteSeeder::class,
            ReasonCodeSeeder::class,
        ]);
    }
}
