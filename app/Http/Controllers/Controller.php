<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;

abstract class Controller
{
    use AuthorizesRequests;

    protected function getStatusCode(\Exception $e): int
    {
        $code = $e->getCode();

        if (is_int($code) && $code >= 100 && $code <= 599) {
            return $code;
        }

        return 400;
    }

    public function successResponse(string $message, $data = null, int $code = 200, array $extra = []): JsonResponse
    {
        return response()->json(array_merge([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $extra), $code);
    }

    public function errorResponse(string $message, $data = null, ?int $code = 400): JsonResponse
    {
        $statusCode = is_int($code) ? $code : 400;

        return response()->json([
            'success' => false,
            'message' => $message,
            'data' => $data,
        ], $statusCode);
    }

    public function notFound(string $message, $data = null): JsonResponse
    {
        return $this->errorResponse($message, $data, 404);
    }

    public function unauthorized(string $message, $data = null): JsonResponse
    {
        return $this->errorResponse($message, $data, 401);
    }

    public function forbidden(string $message, $data = null): JsonResponse
    {
        return $this->errorResponse($message, $data, 403);
    }

    public function validationError(string $message, $errors = null): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => $errors,
        ], 422);
    }

    public function paginated($paginator, $resource, string $message): JsonResponse
    {
        return $this->successResponse(
            $message,
            $resource::collection(collect($paginator->items())),
            extra: $this->paginationPayload($paginator),
        );
    }

    /**
     * Shared `meta`/`links` block for endpoints that paginate but need a custom `data` shape.
     */
    protected function paginationPayload($paginator): array
    {
        return [
            'meta' => [
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
            ],
            'links' => [
                'next' => $paginator->nextPageUrl(),
            ],
        ];
    }
}
