<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RealtimeAuthController extends Controller
{
    /**
     * Handshake endpoint for the Socket.IO sidecar.
     * Validates the Sanctum bearer token and returns the authenticated user.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        return $this->successResponse(__('messages.realtime_authenticated'), [
            'id' => $user->id ?? null,
            'uuid' => $user->uuid,
            'full_name' => $user->full_name ?? $user->user_name,
            'roles' => method_exists($user, 'getRoleNames')
                ? $user->getRoleNames()->values()->all()
                : [],
        ]);
    }
}
