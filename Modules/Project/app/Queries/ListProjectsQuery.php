<?php

namespace Modules\Project\Queries;

use App\Support\Query\BaseQueryService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Modules\Project\Models\Project;

class ListProjectsQuery
{
    public function paginate(Request $request): LengthAwarePaginator
    {
        $query = Project::query()->with(['category', 'creator', 'updater']);
        $perPage = (int) $request->input('per_page', 15);

        $tab = $request->input('tab');

        if ($tab === 'fixed') {
            $query->fixed()->notArchived();
        } elseif ($tab === 'variable') {
            $query->variable()->notArchived();
        } elseif ($tab === 'archived') {
            $query->archived();
        }

        if ($request->filled('status') && $tab !== 'archived') {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('fund_type') && ! $tab) {
            $query->where('fund_type', $request->input('fund_type'));
        }

        if ($request->filled('created_from')) {
            $query->whereDate('created_at', '>=', $request->input('created_from'));
        }

        if ($request->filled('created_to')) {
            $query->whereDate('created_at', '<=', $request->input('created_to'));
        }

        $base = new BaseQueryService($query, $request);
        $base->allowedFilters(['status', 'fund_type', 'category_id'])
            ->allowedSorts(['name', 'created_at', 'status', 'fund_type'])
            ->allowedSearch(['name'])
            ->apply();

        if (! $request->filled('sort')) {
            $query->orderByDesc('created_at');
        }

        return $query->paginate($perPage);
    }
}
