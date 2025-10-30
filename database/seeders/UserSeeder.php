<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class UserSeeder extends Seeder
{
    public function run()
    {
        // Ensure roles exist for 'api' guard (or change to 'web' if you use web guard)
        $roles = ['admin', 'warehouse-manager', 'user'];
        foreach ($roles as $roleName) {
            Role::firstOrCreate([
                'name' => $roleName,
                'guard_name' => 'api', // <-- set to 'api' to match your user guard
            ]);
        }

        // Users data
        $users = [
            [
                'name' => 'Admin User',
                'email' => 'admin@inventorywms.com',
                'role' => 'admin',
                'password' => Hash::make('password'),
            ],
            [
                'name' => 'Manager User',
                'email' => 'manager@inventorywms.com',
                'role' => 'warehouse-manager',
                'password' => Hash::make('password'),
            ],
            [
                'name' => 'Staff User',
                'email' => 'staff@inventorywms.com',
                'role' => 'user',
                'password' => Hash::make('password'),
            ],
        ];

        foreach ($users as $userData) {
            $user = User::create(array_merge($userData, ['email_verified_at' => now()]));
            $user->assignRole($userData['role']); // This will now work
        }
    }
}