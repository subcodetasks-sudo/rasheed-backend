<?php

namespace Modules\AuditLog\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\AuditLog\Enums\AuditAction;
use Modules\AuditLog\Http\Requests\ListMyActivityLogsRequest;
use Modules\AuditLog\Http\Resources\AuditLogResource;
use Modules\AuditLog\Queries\ListAuditLogsQuery;
use Spatie\Activitylog\Models\Activity;

class ListMyActivityLogsController extends Controller
{
    public function __invoke(ListMyActivityLogsRequest $request, ListAuditLogsQuery $query): JsonResponse
    {
        $this->authorize('viewOwn', Activity::class);

        $paginator = $query->paginate($request, (string) $request->user()->uuid);

        return $this->successResponse(
            __('messages.my_activity_logs_fetched_successfully'),
            AuditLogResource::collection(collect($paginator->items())),
            extra: array_merge($this->paginationPayload($paginator), [
                'available_actions' => AuditAction::values(),
            ]),
        );
    }
}
