<?php

namespace Modules\DailyJournal\Events;

use Carbon\CarbonInterface;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Modules\DailyJournal\Http\Resources\DailyJournalEntryResource;
use Modules\DailyJournal\Models\DailyJournalEntry;

class DailyJournalUpdated implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * @param  Collection<int, DailyJournalEntry>  $entries
     */
    public function __construct(
        public readonly CarbonInterface $journalDate,
        public readonly Collection $entries,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new Channel('daily-journals.'.$this->journalDate->toDateString()),
        ];
    }

    public function broadcastAs(): string
    {
        return 'daily-journal.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'journal_date' => $this->journalDate->toDateString(),
            'entries' => DailyJournalEntryResource::collection($this->entries)->resolve(),
        ];
    }
}
