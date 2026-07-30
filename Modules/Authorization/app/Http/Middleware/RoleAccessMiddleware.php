<?php

namespace Modules\Authorization\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleAccessMiddleware
{
    public function handle(Request $request, Closure $next, $allowedRoles): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => __('messages.unauthorized_access'),
            ], 403);
        }

        $allowedRolesArray = explode(',', $allowedRoles);
        $userRoles = $user->roles->pluck('name')->toArray();

        foreach ($allowedRolesArray as $role) {
            if (in_array(trim($role), $userRoles)) {
                return $next($request);
            }
        }

        return response()->json([
            'success' => false,
            'message' => __('messages.insufficient_permissions'),
        ], 403);
    }
}