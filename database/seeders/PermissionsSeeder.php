<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class PermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Define resource names
        $resources = [
            'products',
            'inventory',
            'warehouses',
            'customers',
            'suppliers',
            'sales-orders',
            'purchase-orders',
            'users',
            'roles'
        ];

        // Define actions
        $actions = ['view', 'create', 'edit', 'delete'];

        // Create permissions for each resource
        foreach ($resources as $resource) {
            foreach ($actions as $action) {
                Permission::firstOrCreate([
                    'name' => "$action-$resource",
                    'guard_name' => 'api'
                ]);
            }
        }

        // Create additional special permissions
        $specialPermissions = [
            'adjust-stock',
            'transfer-stock',
            'approve-orders',
            'manage-settings',
            'view-reports',
            'export-data'
        ];

        foreach ($specialPermissions as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'api'
            ]);
        }

        // Create default roles
        $adminRole = Role::firstOrCreate([
            'name' => 'admin',
            'guard_name' => 'api'
        ]);

        $userRole = Role::firstOrCreate([
            'name' => 'user',
            'guard_name' => 'api'
        ]);

        $warehouseManagerRole = Role::firstOrCreate([
            'name' => 'warehouse-manager',
            'guard_name' => 'api'
        ]);

        // Assign all permissions to admin
        $adminRole->syncPermissions(Permission::all());

        // Assign basic view permissions to user
        $userRole->syncPermissions([
            'view-products',
            'view-inventory',
            'view-warehouses'
        ]);

        // Assign warehouse-specific permissions to warehouse manager
        $warehouseManagerRole->syncPermissions([
            'view-products',
            'create-products',
            'edit-products',
            'view-inventory',
            'create-inventory',
            'edit-inventory',
            'adjust-stock',
            'transfer-stock',
            'view-warehouses',
            'view-purchase-orders',
            'create-purchase-orders'
        ]);

        $this->command->info('Permissions and roles created successfully!');
    }
}