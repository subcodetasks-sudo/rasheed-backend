<?php

namespace Modules\DailyJournal\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Project\Models\Project;

class AdminPercentagePoolReliefAllocation extends Model
{
    protected $fillable = [
        'relief_entry_id',
        'source_project_id',
        'beneficiary_project_id',
        'journal_date',
        'amount',
    ];

    protected function casts(): array
    {
        return [
            'journal_date' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    public function reliefEntry(): BelongsTo
    {
        return $this->belongsTo(DailyJournalEntry::class, 'relief_entry_id');
    }

    public function sourceProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'source_project_id');
    }

    public function beneficiaryProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'beneficiary_project_id');
    }

    public function scopeForBeneficiary(Builder $query, int $projectId, string $journalDate): Builder
    {
        return $query->where('beneficiary_project_id', $projectId)->whereDate('journal_date', $journalDate);
    }

    public function scopeForSource(Builder $query, int $projectId, string $journalDate): Builder
    {
        return $query->where('source_project_id', $projectId)->whereDate('journal_date', $journalDate);
    }

    public function scopeForDate(Builder $query, string $journalDate): Builder
    {
        return $query->whereDate('journal_date', $journalDate);
    }
}
