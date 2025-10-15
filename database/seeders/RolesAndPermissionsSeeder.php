<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run()
    {
        // Reset cached roles and permissions
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // Define permissions by group
        $permissions = [
            // Products
            'products.view',
            'products.create',
            'products.edit',
            'products.delete',
            'products.export',
            'products.import',

            // Inventory
            'inventory.view',
            'inventory.adjust',
            'inventory.transfer',
            'inventory.export',

            // Sales Orders
            'sales-orders.view',
            'sales-orders.create',
            'sales-orders.edit',
            'sales-orders.delete',
            'sales-orders.approve',
            'sales-orders.export',

            // Purchase Orders
            'purchase-orders.view',
            'purchase-orders.create',
            'purchase-orders.edit',
            'purchase-orders.delete',
            'purchase-orders.approve',
            'purchase-orders.export',

            // Customers
            'customers.view',
            'customers.create',
            'customers.edit',
            'customers.delete',
            'customers.export',
            'customers.import',

            // Suppliers
            'suppliers.view',
            'suppliers.create',
            'suppliers.edit',
            'suppliers.delete',
            'suppliers.export',

            // Warehouses
            'warehouses.view',
            'warehouses.create',
            'warehouses.edit',
            'warehouses.delete',

            // Reports
            'reports.view',
            'reports.export',

            // Users
            'users.view',
            'users.create',
            'users.edit',
            'users.delete',

            // Roles
            'roles.view',
            'roles.create',
            'roles.edit',
            'roles.delete',

            // Settings
            'settings.view',
            'settings.edit',
        ];

        // Create permissions
        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'api']);
        }

        // Create roles and assign permissions
        $this->createSuperAdminRole();
        $this->createAdminRole();
        $this->createManagerRole();
        $this->createStaffRole();
        $this->createViewerRole();

        $this->command->info('Roles and permissions seeded successfully!');
    }

    private function createSuperAdminRole()
    {
        $role = Role::firstOrCreate(
            ['name' => 'super-admin', 'guard_name' => 'api'],
        );

        // Super admin gets all permissions
        $role->syncPermissions(Permission::all());

        $this->command->info('Super Admin role created');
    }

    private function createAdminRole()
    {
        $role = Role::firstOrCreate(
            ['name' => 'admin', 'guard_name' => 'web'],
            ['name' => 'admin', 'guard_name' => 'api'],
        );

        // Admin gets all except settings
        $permissions = Permission::where('name', 'not like', 'settings.%')->get();
        $role->syncPermissions($permissions);

        $this->command->info('Admin role created');
    }

    private function createManagerRole()
    {
        $role = Role::firstOrCreate(
            ['name' => 'manager', 'guard_name' => 'api'],
        );

        // Manager can view all, edit most, but not delete or manage users
        $permissions = Permission::where('name', 'not like', '%.delete')
            ->where('name', 'not like', 'users.%')
            ->where('name', 'not like', 'roles.%')
            ->where('name', 'not like', 'settings.%')
            ->get();

        $role->syncPermissions($permissions);

        $this->command->info('Manager role created');
    }

    private function createStaffRole()
    {
        $role = Role::firstOrCreate(
            ['name' => 'staff', 'guard_name' => 'api'],
        );

        // Staff can view, create, and edit basic operations
        $staffPermissions = [
            'products.view',
            'products.create',
            'products.edit',
            'inventory.view',
            'inventory.adjust',
            'sales-orders.view',
            'sales-orders.create',
            'sales-orders.edit',
            'purchase-orders.view',
            'purchase-orders.create',
            'purchase-orders.edit',
            'customers.view',
            'customers.create',
            'customers.edit',
            'suppliers.view',
            'warehouses.view',
            'reports.view',
        ];

        $permissions = Permission::whereIn('name', $staffPermissions)->get();
        $role->syncPermissions($permissions);

        $this->command->info('Staff role created');
    }

    private function createViewerRole()
    {
        $role = Role::firstOrCreate(
            ['name' => 'viewer', 'guard_name' => 'api'],
        );

        // Viewer can only view
        $permissions = Permission::where('name', 'like', '%.view')->get();
        $role->syncPermissions($permissions);

        $this->command->info('Viewer role created');
    }
}