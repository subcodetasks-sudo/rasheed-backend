<?php

namespace Modules\User\app\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CheckUserStatus
{
    public function handle(Request $request, Closure $next)
    {
        if (Auth::check()) {
            $user = Auth::user();

            if (!$user->isActive()) {
                return response()->json([
                    'success' => false,
                    'message' => __('auth.errors.unauthorized'),
                    'data' => null
                ], 403);
            }
        }

        return $next($request);
    }
}