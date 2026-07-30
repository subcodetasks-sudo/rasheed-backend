<?php

use App\Http\Middleware\RateLimitMiddleware;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Modules\Authorization\Http\Middleware\RoleAccessMiddleware;
use Modules\User\app\Http\Middleware\CheckUserStatus;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\PermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'SetLocale' => SetLocale::class,
            'rate_limit' => RateLimitMiddleware::class,
            'CheckStatus' => CheckUserStatus::class,
            'role_access' => RoleAccessMiddleware::class,
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->renderable(function (\Modules\Project\Exceptions\BusinessException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getCode() >= 100 && $e->getCode() <= 599 ? $e->getCode() : 422);
        });
    })->create();
