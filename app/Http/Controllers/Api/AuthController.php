<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'role' => 'user', // default role
            'password' => Hash::make($request->password),
        ]);

        $user->assignRole('user');

        $request->merge(['plan_id' => 1]); // add the plan ID to the request data
        $request->setUserResolver(fn() => $user); // make $request->user() return this user

        $subscriptionResponse = app(SubscriptionController::class)->startTrial($request);

        $data = $subscriptionResponse->getData(true);

        if (!$data['success']) {
            $message = 'Subscription setup failed after registration.';
        } else {
            $message = 'Trial subscription started successfully.';
        }

        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token,
            'subscription' => $data['data'] ?? null,
            'message' => $message,
        ], 201);
    }

    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        if (!Auth::attempt($request->only('email', 'password'))) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $user = Auth::user();


        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out successfully']);
    }

    public function user(Request $request)
    {
        return response()->json($request->user());
    }

    /**
     * Get current user with roles and permissions.
     */
    public function me(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated'
            ], 401);
        }

        // Get user's role IDs
        $roleIds = DB::table('model_has_roles')
            ->where('model_id', $user->id)
            ->where('model_type', get_class($user))
            ->pluck('role_id');

        // Get roles
        $roles = DB::table('roles')
            ->whereIn('id', $roleIds)
            ->get();

        // Get permission IDs from user's roles
        $permissionIds = DB::table('role_has_permissions')
            ->whereIn('role_id', $roleIds)
            ->pluck('permission_id')
            ->unique();

        // Get permissions
        $permissions = DB::table('permissions')
            ->whereIn('id', $permissionIds)
            ->pluck('name');

        return response()->json([
            'success' => true,
            'data' => [
                'user' => $user,
                'roles' => $roles->pluck('name'),
                'permissions' => $permissions
            ]
        ]);
    }

    /**
     * Check if current user has a specific permission.
     */
    public function checkPermission(Request $request, string $permission)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'has_permission' => false
            ]);
        }

        // Get user's role IDs
        $roleIds = DB::table('model_has_roles')
            ->where('model_id', $user->id)
            ->where('model_type', get_class($user))
            ->pluck('role_id');

        // Get permission ID
        $permissionRecord = DB::table('permissions')
            ->where('name', $permission)
            ->first();

        if (!$permissionRecord) {
            return response()->json([
                'success' => false,
                'has_permission' => false,
                'message' => 'Permission not found'
            ]);
        }

        // Check if any of user's roles have this permission
        $hasPermission = DB::table('role_has_permissions')
            ->whereIn('role_id', $roleIds)
            ->where('permission_id', $permissionRecord->id)
            ->exists();

        return response()->json([
            'success' => true,
            'has_permission' => $hasPermission
        ]);
    }
}