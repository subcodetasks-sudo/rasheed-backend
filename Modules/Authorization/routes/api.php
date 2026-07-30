<?php

use App\Http\Middleware\SetLocale;
use Illuminate\Support\Facades\Route;
use Modules\Authorization\app\Http\Controllers\V1\CreateUserController;
use Modules\Authorization\app\Http\Controllers\V1\ListRolesController;
use Modules\Authorization\app\Http\Controllers\V1\UserController;
// use Modules\Authorization\Http\Middleware\RoleAccessMiddleware;

Route::prefix('v1')->middleware([SetLocale::class, 'auth:sanctum', 'role:super-admin'])->group(function () {

  Route::get('/roles', ListRolesController::class);

  Route::get('/users', [UserController::class, 'index']);
  // Route::post('/users', CreateUserController::class);
  Route::post('/users/{user}', [UserController::class, 'update']);
  Route::delete('/users/{user}', [UserController::class, 'destroy']);
  Route::post('users/{user}/status', [UserController::class, 'updateStatus']);
});