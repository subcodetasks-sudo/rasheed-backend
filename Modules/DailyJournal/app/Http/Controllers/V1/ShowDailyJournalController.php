<?php

namespace Modules\DailyJournal\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\DailyJournal\Http\Requests\ShowDailyJournalRequest;
use Modules\DailyJournal\Http\Resources\DailyJournalResource;
use Modules\DailyJournal\Workflows\ShowDailyJournalWorkflow;

class ShowDailyJournalController extends Controller
{
    public function __invoke(
        ShowDailyJournalRequest $request,
        ShowDailyJournalWorkflow $workflow
    ): JsonResponse {
        $journalDate = $request->journalDate();

        $entries = $workflow->handle($journalDate, $request->perPage(), $request->page());

        return $this->successResponse(
            __('messages.daily_journal_fetched_successfully'),
            new DailyJournalResource([
                'journal_date' => $journalDate->toDateString(),
                'entries' => collect($entries->items()),
            ]),
            extra: $this->paginationPayload($entries),
        );
    }
}
