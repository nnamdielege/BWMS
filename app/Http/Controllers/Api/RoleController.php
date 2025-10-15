<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RoleController extends Controller
{
    public function index()
    {
        $roles = Role::with('permissions')->get();

        return response()->json([
            'data' => $roles
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:roles,name',
            'permissions' => 'nullable|array',
            'permissions.*' => 'exists:permissions,name',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $role = Role::create([
            'name' => $request->name,
            'guard_name' => 'sanctum',
        ]);

        if ($request->has('permissions')) {
            $role->syncPermissions($request->permissions);
        }

        return response()->json([
            'message' => 'Role created successfully',
            'data' => $role->load('permissions')
        ], 201);
    }

    public function show($id)
    {
        $role = Role::with('permissions')->findOrFail($id);

        return response()->json([
            'data' => $role
        ]);
    }

    public function update(Request $request, $id)
    {
        $role = Role::findOrFail($id);

        // Prevent editing system roles
        $systemRoles = ['super-admin', 'admin', 'manager', 'staff', 'viewer'];
        if (in_array($role->name, $systemRoles)) {
            return response()->json([
                'message' => 'System roles cannot be edited. You can only modify their permissions.'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:roles,name,' . $id,
            'permissions' => 'nullable|array',
            'permissions.*' => 'exists:permissions,name',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $role->update([
            'name' => $request->name,
        ]);

        if ($request->has('permissions')) {
            $role->syncPermissions($request->permissions);
        }

        return response()->json([
            'message' => 'Role updated successfully',
            'data' => $role->load('permissions')
        ]);
    }

    public function updatePermissions(Request $request, $id)
    {
        $role = Role::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'permissions' => 'required|array',
            'permissions.*' => 'exists:permissions,name',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $role->syncPermissions($request->permissions);

        return response()->json([
            'message' => 'Permissions updated successfully',
            'data' => $role->load('permissions')
        ]);
    }

    public function destroy($id)
    {
        $role = Role::findOrFail($id);

        // Prevent deleting system roles
        $systemRoles = ['super-admin', 'admin', 'manager', 'staff', 'viewer'];
        if (in_array($role->name, $systemRoles)) {
            return response()->json([
                'message' => 'System roles cannot be deleted'
            ], 403);
        }

        // Check if role has users
        if ($role->users()->count() > 0) {
            return response()->json([
                'message' => 'Cannot delete role with assigned users. Please reassign users first.'
            ], 422);
        }

        $role->delete();

        return response()->json([
            'message' => 'Role deleted successfully'
        ]);
    }

    public function permissions()
    {
        $permissions = Permission::all()->groupBy(function ($permission) {
            // Group by prefix (e.g., 'products', 'inventory', etc.)
            return explode('.', $permission->name)[0];
        });

        return response()->json([
            'data' => $permissions
        ]);
    }
}