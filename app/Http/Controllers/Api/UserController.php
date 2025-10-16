<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    /**
     * Display a listing of users with their roles.
     */
    public function index()
    {
        $users = User::orderBy('name')->get();

        // Manually load roles for each user
        $users = $users->map(function ($user) {
            // Get roles from pivot table
            $roleIds = DB::table('model_has_roles')
                ->where('model_id', $user->id)
                ->where('model_type', User::class)
                ->pluck('role_id');

            // Get role details
            $roles = Role::whereIn('id', $roleIds)->get();

            // Add roles to user object
            $userData = $user->toArray();
            $userData['roles'] = $roles->toArray();

            return $userData;
        });

        return response()->json([
            'success' => true,
            'data' => $users
        ]);
    }

    /**
     * Assign a single role to a user (backward compatibility).
     */
    public function assignRole(Request $request, User $user)
    {
        $request->validate([
            'role' => 'nullable|string|exists:roles,name'
        ]);

        // Remove all existing roles
        DB::table('model_has_roles')
            ->where('model_id', $user->id)
            ->where('model_type', User::class)
            ->delete();

        // Assign new role if provided
        if ($request->role) {
            $role = Role::where('name', $request->role)->first();

            if ($role) {
                DB::table('model_has_roles')->insert([
                    'role_id' => $role->id,
                    'model_type' => User::class,
                    'model_id' => $user->id,
                ]);

                // Update the role field in users table
                $user->role = $request->role;
                $user->save();
            }
        } else {
            // Clear role field if no role assigned
            $user->role = null;
            $user->save();
        }

        // Clear permission cache
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Get updated user with roles
        $roleIds = DB::table('model_has_roles')
            ->where('model_id', $user->id)
            ->where('model_type', User::class)
            ->pluck('role_id');

        $roles = Role::whereIn('id', $roleIds)->get();

        return response()->json([
            'success' => true,
            'message' => 'Role assigned successfully',
            'data' => array_merge($user->toArray(), ['roles' => $roles->toArray()])
        ]);
    }

    /**
     * Assign multiple roles to a user.
     */
    public function assignRoles(Request $request, User $user)
    {
        $request->validate([
            'roles' => 'nullable|array',
            'roles.*' => 'string|exists:roles,name'
        ]);

        // Remove all existing roles first
        DB::table('model_has_roles')
            ->where('model_id', $user->id)
            ->where('model_type', User::class)
            ->delete();

        // Assign new roles if provided
        if ($request->has('roles') && is_array($request->roles) && count($request->roles) > 0) {
            $roles = Role::whereIn('name', $request->roles)->get();

            $insertData = $roles->map(function ($role) use ($user) {
                return [
                    'role_id' => $role->id,
                    'model_type' => User::class,
                    'model_id' => $user->id,
                ];
            })->toArray();

            DB::table('model_has_roles')->insert($insertData);

            // Update the role field in users table
            // Store first role or comma-separated list of roles
            $user->role = implode(',', $request->roles);
            $user->save();
        } else {
            // Clear role field if no roles assigned
            $user->role = null;
            $user->save();
        }

        // Clear permission cache
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Get updated user with roles
        $roleIds = DB::table('model_has_roles')
            ->where('model_id', $user->id)
            ->where('model_type', User::class)
            ->pluck('role_id');

        $roles = Role::whereIn('id', $roleIds)->get();

        return response()->json([
            'success' => true,
            'message' => 'Roles assigned successfully',
            'data' => array_merge($user->toArray(), ['roles' => $roles->toArray()])
        ]);
    }

    /**
     * Remove a specific role from a user.
     */
    public function removeRole(User $user, $roleId)
    {
        // Get role name before removing
        $role = Role::find($roleId);

        if (!$role) {
            return response()->json([
                'success' => false,
                'message' => 'Role not found'
            ], 404);
        }

        // Remove the specific role
        DB::table('model_has_roles')
            ->where('model_id', $user->id)
            ->where('model_type', User::class)
            ->where('role_id', $roleId)
            ->delete();

        // Update user's role field
        $remainingRoleIds = DB::table('model_has_roles')
            ->where('model_id', $user->id)
            ->where('model_type', User::class)
            ->pluck('role_id');

        if ($remainingRoleIds->count() > 0) {
            $remainingRoles = Role::whereIn('id', $remainingRoleIds)->pluck('name')->toArray();
            $user->role = implode(',', $remainingRoles);
        } else {
            $user->role = null;
        }
        $user->save();

        // Clear permission cache
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        return response()->json([
            'success' => true,
            'message' => 'Role removed successfully'
        ]);
    }

    /**
     * Remove all roles from a user.
     */
    public function removeAllRoles(User $user)
    {
        // Remove all roles
        DB::table('model_has_roles')
            ->where('model_id', $user->id)
            ->where('model_type', User::class)
            ->delete();

        // Clear role field
        $user->role = null;
        $user->save();

        // Clear permission cache
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        return response()->json([
            'success' => true,
            'message' => 'All roles removed successfully'
        ]);
    }
}