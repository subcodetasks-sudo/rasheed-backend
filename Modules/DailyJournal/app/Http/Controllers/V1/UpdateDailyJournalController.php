<?php

namespace Modules\DailyJournal\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\DailyJournal\DTOs\DailyJournalData;
use Modules\DailyJournal\Http\Requests\UpdateDailyJournalRequest;
use Modules\DailyJournal\Http\Resources\DailyJournalResource;
use Modules\DailyJournal\Workflows\UpdateDailyJournalWorkflow;

class UpdateDailyJournalController extends Controller
{
    public function __invoke(
        UpdateDailyJournalRequest $request,
        UpdateDailyJournalWorkflow $workflow
    ): JsonResponse {
        $data = DailyJournalData::fromArray($request->validated());
        $entries = $workflow->handle($data);

        return $this->successResponse(
            __('messages.daily_journal_updated_successfully'),
            new DailyJournalResource([
                'journal_date' => $data->journalDate->toDateString(),
                'entries' => $entries,
            ])
        );
    }
}
