<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\Models\Role;

class UserRoleController extends Controller
{
    public function index(Request $request)
    {
        $query = User::with('roles');

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // Filter by role
        if ($request->has('role')) {
            $query->role($request->role);
        }

        $users = $query->paginate(15);

        return response()->json($users);
    }

    public function show($id)
    {
        $user = User::with('roles.permissions')->findOrFail($id);

        return response()->json([
            'data' => $user
        ]);
    }

    public function syncRoles(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'roles' => 'required|array',
            'roles.*' => 'exists:roles,name',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = User::findOrFail($id);

        // Prevent removing super-admin from yourself
        if ($user->id === auth()->id() && $user->hasRole('super-admin')) {
            if (!in_array('super-admin', $request->roles)) {
                return response()->json([
                    'message' => 'You cannot remove super-admin role from yourself'
                ], 403);
            }
        }

        $user->syncRoles($request->roles);

        return response()->json([
            'message' => 'User roles updated successfully',
            'data' => $user->load('roles')
        ]);
    }

    public function getPermissions($id)
    {
        $user = User::findOrFail($id);

        // Get all permissions (direct + via roles)
        $permissions = $user->getAllPermissions()->pluck('name');

        return response()->json([
            'data' => $permissions
        ]);
    }
}