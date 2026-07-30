<?php

namespace Modules\DailyJournal\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

class DailyJournalResource extends JsonResource
{
    /**
     * @param  array{journal_date: string, entries: Collection}  $resource
     */
    public function toArray(Request $request): array
    {
        return [
            'journal_date' => $this->resource['journal_date'],
            'entries' => DailyJournalEntryResource::collection($this->resource['entries']),
        ];
    }
}
