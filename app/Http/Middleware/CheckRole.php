<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated'
            ], 401);
        }

        // Check if user has any of the required roles
        if ($this->userHasAnyRole($user, $roles)) {
            return $next($request);
        }

        return response()->json([
            'success' => false,
            'message' => 'You do not have the required role to perform this action',
            'required_roles' => $roles
        ], 403);
    }

    /**
     * Check if user has any of the specified roles.
     */
    private function userHasAnyRole($user, array $roleNames): bool
    {
        // Get role IDs for the specified role names
        $requiredRoleIds = DB::table('roles')
            ->whereIn('name', $roleNames)
            ->pluck('id');

        if ($requiredRoleIds->isEmpty()) {
            return false;
        }

        // Check if user has any of these roles
        $hasRole = DB::table('model_has_roles')
            ->where('model_id', $user->id)
            ->where('model_type', get_class($user))
            ->whereIn('role_id', $requiredRoleIds)
            ->exists();

        return $hasRole;
    }
}