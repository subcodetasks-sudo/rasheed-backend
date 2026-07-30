<?php

use Illuminate\Support\Facades\Route;
use Modules\User\app\Http\Controllers\V1\AuthController;
use Modules\User\app\Http\Controllers\V1\CreateUserController;
use Modules\User\app\Http\Controllers\V1\DeleteUserController;
use Modules\User\app\Http\Controllers\V1\EditUserController;
use Modules\User\app\Http\Controllers\V1\ListUsersController;

Route::prefix('v1/auth')->group(function () {

    Route::post('login', [AuthController::class, 'login'])->middleware('rate_limit:auth');
    Route::post('refresh', [AuthController::class, 'refresh'])->middleware('rate_limit:auth');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout'])->middleware('rate_limit:user');
        Route::middleware('role:super-admin')->group(function () {
            Route::get('users', ListUsersController::class);
            Route::post('users', CreateUserController::class);
            Route::patch('users/{user}', EditUserController::class);
            Route::delete('users/{user}', DeleteUserController::class);
        });
    });
});
