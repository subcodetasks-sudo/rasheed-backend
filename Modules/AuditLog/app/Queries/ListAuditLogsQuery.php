<?php

namespace Modules\AuditLog\Queries;

use App\Support\Query\BaseQueryService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;

class ListAuditLogsQuery
{
    public function paginate(Request $request): LengthAwarePaginator
    {
        $query = $this->filteredQuery($request);
        $perPage = (int) $request->input('per_page', 15);

        $this->applyDefaultSort($query, $request);

        return $query->paginate($perPage);
    }

    public function filteredQuery(Request $request): Builder
    {
        $query = Activity::query()
            ->where('log_name', config('auditlog.log_name', 'audit'))
            ->with('causer');

        $this->applyMappedFilters($query, $request);
        $this->applyDateRangeFilters($query, $request);

        (new BaseQueryService($query, $request))
            ->allowedFilters([])
            ->allowedSorts(['created_at'])
            ->allowedSearch([])
            ->apply();

        return $query;
    }

    private function applyMappedFilters(Builder $query, Request $request): void
    {
        $userId = $request->input('filter.user_id');
        if (is_string($userId) && $userId !== '') {
            $query->where('causer_id', $userId);
        }

        $action = $request->input('filter.action');
        if (is_string($action) && $action !== '') {
            $query->where('event', $action);
        }
    }

    private function applyDateRangeFilters(Builder $query, Request $request): void
    {
        $from = $request->input('filter.created_from');
        $to = $request->input('filter.created_to');

        if ($from) {
            $query->whereDate('created_at', '>=', $from);
        }

        if ($to) {
            $query->whereDate('created_at', '<=', $to);
        }
    }

    private function applyDefaultSort(Builder $query, Request $request): void
    {
        if ($request->filled('sort')) {
            return;
        }

        $query->orderByDesc('created_at')->orderByDesc('id');
    }
}
