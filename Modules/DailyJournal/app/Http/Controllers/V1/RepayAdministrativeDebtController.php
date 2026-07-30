<?php

namespace Modules\DailyJournal\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\DailyJournal\Actions\RepayAdministrativeDebtAction;
use Modules\DailyJournal\Http\Requests\RepayAdministrativeDebtRequest;
use Modules\DailyJournal\Http\Resources\DailyJournalEntryResource;

class RepayAdministrativeDebtController extends Controller
{
    public function __invoke(
        RepayAdministrativeDebtRequest $request,
        RepayAdministrativeDebtAction $action
    ): JsonResponse {
        $entry = $action->execute($request->journalDate(), $request->projectId());

        return $this->successResponse(
            __('messages.administrative_debt_repaid_successfully'),
            new DailyJournalEntryResource($entry)
        );
    }
}
