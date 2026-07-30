<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Cache\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class RateLimitMiddleware
{
    public function __construct(private RateLimiter $limiter) {}

    public function handle(Request $request, Closure $next, string $key = 'api'): Response
    {
        $limiterKey = $key . ':' . ($request->ip() ?? 'unknown');

        if ($this->limiter->tooManyAttempts($limiterKey, $this->maxAttempts($key))) {
            return response()->json([
                'success' => false,
                'message' => __('auth.throttle'),
            ], 429);
        }

        $this->limiter->hit($limiterKey, $this->decaySeconds($key));

        $response = $next($request);

        return $response;
    }

    private function maxAttempts(string $key): int
    {
        return match ($key) {
            'auth' => 5,
            'user' => 60,
            'search' => 30,
            default => 60,
        };
    }

    private function decaySeconds(string $key): int
    {
        return match ($key) {
            'auth' => 60,
            default => 60,
        };
    }
}