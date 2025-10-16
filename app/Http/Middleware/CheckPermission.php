<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated'
            ], 401);
        }

        // Check if user has the permission
        if ($this->userHasPermission($user, $permission)) {
            return $next($request);
        }

        return response()->json([
            'success' => false,
            'message' => 'You do not have permission to perform this action',
            'required_permission' => $permission
        ], 403);
    }

    /**
     * Check if user has a specific permission.
     */
    private function userHasPermission($user, string $permissionName): bool
    {
        // Get user's role IDs
        $roleIds = DB::table('model_has_roles')
            ->where('model_id', $user->id)
            ->where('model_type', get_class($user))
            ->pluck('role_id');

        if ($roleIds->isEmpty()) {
            return false;
        }

        // Get permission ID
        $permission = DB::table('permissions')
            ->where('name', $permissionName)
            ->first();

        if (!$permission) {
            return false;
        }

        // Check if any of user's roles have this permission
        $hasPermission = DB::table('role_has_permissions')
            ->whereIn('role_id', $roleIds)
            ->where('permission_id', $permission->id)
            ->exists();

        return $hasPermission;
    }
}