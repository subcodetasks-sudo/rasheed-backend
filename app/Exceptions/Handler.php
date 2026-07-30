<?php

namespace App\Exceptions;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Validation\ValidationException;
use Modules\Project\Exceptions\BusinessException;
use Throwable;

class Handler extends ExceptionHandler
{
    public function render($request, Throwable $exception)
    {
        $controller = app(Controller::class);

        if ($exception instanceof BusinessException) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], $exception->getCode() >= 100 && $exception->getCode() <= 599 ? $exception->getCode() : 422);
        }

        if ($exception instanceof ModelNotFoundException) {
            return $controller->notFound('Resource not found');
        }

        if ($exception instanceof AuthenticationException) {
            return $controller->unauthorized($exception->getMessage() ?: 'Unauthenticated');
        }

        if ($exception instanceof AuthorizationException) {
            return $controller->forbidden($exception->getMessage() ?: 'Forbidden');
        }

        if ($exception instanceof ValidationException) {
            return $controller->validationError('Validation failed', $exception->errors());
        }

        if ($exception instanceof ThrottleRequestsException) {
            return response()->json([
                'success' => false,
                'message' => 'Too many requests',
                'data' => null,
            ], 429, ['Retry-After' => $exception->getHeaders()['Retry-After'] ?? 60]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Server Error',
            'data' => null,
        ], 500);
    }
}
