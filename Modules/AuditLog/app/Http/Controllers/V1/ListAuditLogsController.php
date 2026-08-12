<?php

namespace Modules\AuditLog\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\AuditLog\Enums\AuditAction;
use Modules\AuditLog\Http\Requests\ListAuditLogsRequest;
use Modules\AuditLog\Http\Resources\AuditLogResource;
use Modules\AuditLog\Queries\ListAuditLogsQuery;
use Spatie\Activitylog\Models\Activity;

class ListAuditLogsController extends Controller
{
    public function __invoke(ListAuditLogsRequest $request, ListAuditLogsQuery $query): JsonResponse
    {
        $this->authorize('viewAny', Activity::class);

        $paginator = $query->paginate($request);

        return $this->successResponse(
            __('messages.audit_logs_fetched_successfully'),
            AuditLogResource::collection(collect($paginator->items())),
            extra: array_merge($this->paginationPayload($paginator), [
                'available_actions' => AuditAction::values(),
            ]),
        );
    }
}
