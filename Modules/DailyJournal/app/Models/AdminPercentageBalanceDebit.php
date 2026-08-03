<?php

namespace Modules\DailyJournal\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminPercentageBalanceDebit extends Model
{
    protected $fillable = [
        'daily_journal_entry_id',
        'amount',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(DailyJournalEntry::class, 'daily_journal_entry_id');
    }
}
