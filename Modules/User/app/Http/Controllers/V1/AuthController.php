<?php

namespace Modules\User\app\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Modules\User\app\Http\Requests\LoginRequest;
use Modules\User\app\Http\Requests\RefreshTokenRequest;
use Modules\User\Services\AuthService;

class AuthController extends Controller
{
    public function __construct(private AuthService $authService) {}

    public function login(LoginRequest $request): JsonResponse
    {
        try {
            $data = $this->authService->login($request->validated());

            return $this->successResponse(__('auth.messages.logged_in_successfully'), $data);
        } catch (ValidationException|AuthorizationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Auth Error: '.$e->getMessage());

            return $this->errorResponse(__('messages.unexpected_error'), null, $this->getStatusCode($e));
        }
    }

    public function refresh(RefreshTokenRequest $request): JsonResponse
    {
        try {
            $data = $this->authService->refresh($request->validated('refresh_token'));

            return $this->successResponse(__('auth.messages.token_refreshed_successfully'), $data);
        } catch (ValidationException|AuthorizationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Auth Error: '.$e->getMessage());

            return $this->errorResponse(__('messages.unexpected_error'), null, $this->getStatusCode($e));
        }
    }

    public function logout(Request $request): JsonResponse
    {
        try {
            $this->authService->logout($request->user());

            return $this->successResponse(__('auth.messages.logged_out_successfully'));
        } catch (ValidationException|AuthorizationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Auth Error: '.$e->getMessage());

            return $this->errorResponse(__('messages.unexpected_error'), null, $this->getStatusCode($e));
        }
    }
}
