<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RoleController extends Controller
{
    /**
     * Display a listing of roles.
     */
    public function index()
    {
        $roles = Role::with('permissions')
            ->orderBy('name')
            ->get()
            ->map(function ($role) {
                // Manually count users for this role from the pivot table
                $usersCount = DB::table('model_has_roles')
                    ->where('role_id', $role->id)
                    ->where('model_type', User::class)
                    ->count();

                return [
                    'id' => $role->id,
                    'name' => $role->name,
                    'guard_name' => $role->guard_name,
                    'permissions_count' => $role->permissions->count(),
                    'users_count' => $usersCount,
                    'created_at' => $role->created_at,
                    'updated_at' => $role->updated_at,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $roles
        ]);
    }

    /**
     * Store a newly created role.
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|unique:roles,name|max:255'
        ]);

        $role = Role::create([
            'name' => $request->name,
            'guard_name' => 'api'
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Role created successfully',
            'data' => $role
        ], 201);
    }

    /**
     * Display the specified role with its permissions.
     */
    public function show(Role $role)
    {
        $role->load('permissions');

        return response()->json([
            'success' => true,
            'data' => $role
        ]);
    }

    /**
     * Update the specified role.
     */
    public function update(Request $request, Role $role)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:roles,name,' . $role->id
        ]);

        $role->update([
            'name' => $request->name
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Role updated successfully',
            'data' => $role
        ]);
    }

    /**
     * Remove the specified role.
     */
    public function destroy(Role $role)
    {
        // Prevent deletion of system roles
        if (in_array($role->name, ['admin', 'user'])) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete system roles'
            ], 403);
        }

        $roleId = $role->id;

        // Manually detach all users from this role before deleting
        DB::table('model_has_roles')
            ->where('role_id', $roleId)
            ->delete();

        // Manually detach all permissions from this role before deleting
        DB::table('role_has_permissions')
            ->where('role_id', $roleId)
            ->delete();

        // Delete directly from database to avoid model events
        DB::table('roles')
            ->where('id', $roleId)
            ->delete();

        // Clear permission cache
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        return response()->json([
            'success' => true,
            'message' => 'Role deleted successfully'
        ]);
    }

    /**
     * Get all permissions.
     */
    public function permissions()
    {
        $permissions = Permission::orderBy('name')->get();

        return response()->json([
            'success' => true,
            'data' => $permissions
        ]);
    }

    /**
     * Update role permissions.
     */
    public function updatePermissions(Request $request, Role $role)
    {
        $request->validate([
            'permissions' => 'required|array',
            'permissions.*' => 'exists:permissions,id'
        ]);

        // Manually sync permissions to avoid guard issues
        // First, remove all existing permissions
        DB::table('role_has_permissions')
            ->where('role_id', $role->id)
            ->delete();

        // Then, add the new permissions
        $permissionsData = collect($request->permissions)->map(function ($permissionId) use ($role) {
            return [
                'permission_id' => $permissionId,
                'role_id' => $role->id,
            ];
        })->toArray();

        if (count($permissionsData) > 0) {
            DB::table('role_has_permissions')->insert($permissionsData);
        }

        // Clear permission cache
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Reload permissions
        $role->load('permissions');

        return response()->json([
            'success' => true,
            'message' => 'Permissions updated successfully',
            'data' => $role
        ]);
    }

    /**
     * Remove a specific permission from a role.
     */
    public function removePermission(Request $request, Role $role, $permissionId)
    {
        // Remove the permission from this role
        DB::table('role_has_permissions')
            ->where('role_id', $role->id)
            ->where('permission_id', $permissionId)
            ->delete();

        // Clear permission cache
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        return response()->json([
            'success' => true,
            'message' => 'Permission removed successfully'
        ]);
    }

    /**
     * Remove all permissions from a role.
     */
    public function removeAllPermissions(Role $role)
    {
        // Remove all permissions from this role
        DB::table('role_has_permissions')
            ->where('role_id', $role->id)
            ->delete();

        // Clear permission cache
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        return response()->json([
            'success' => true,
            'message' => 'All permissions removed successfully'
        ]);
    }

    /**
     * Delete a permission entirely from the system.
     */
    public function destroyPermission($permissionId)
    {
        // Remove permission from all roles
        DB::table('role_has_permissions')
            ->where('permission_id', $permissionId)
            ->delete();

        // Remove permission from all users
        DB::table('model_has_permissions')
            ->where('permission_id', $permissionId)
            ->delete();

        // Delete the permission
        DB::table('permissions')
            ->where('id', $permissionId)
            ->delete();

        // Clear permission cache
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        return response()->json([
            'success' => true,
            'message' => 'Permission deleted successfully'
        ]);
    }
}