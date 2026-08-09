<?php

namespace Modules\Notifications\Actions;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Modules\Notifications\Models\Notification;
use Modules\Notifications\Support\NotificationPageTypeMapper;

class ListNotificationsAction
{
    public function execute(Request $request): LengthAwarePaginator
    {
        $query = Notification::query()
            ->with('project')
            ->orderByDesc('id');

        $pageType = $request->input('filter.type');

        if (is_string($pageType) && $pageType !== '') {
            $dbTypes = NotificationPageTypeMapper::dbTypesForPageType($pageType);

            if ($dbTypes !== []) {
                $query->whereIn('type', $dbTypes);
            }
        }

        $perPage = (int) $request->input('per_page', 15);
        $perPage = max(1, min($perPage, 100));

        return $query->paginate($perPage);
    }
}
