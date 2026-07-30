<?php

namespace Modules\DailyJournal\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\DailyJournal\Database\Factories\DailyJournalEntryFactory;
use Modules\Project\Models\Project;
use Modules\User\app\Models\User;

class DailyJournalEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'journal_date',
        'project_id',
        'daily_income',
        'daily_expense',
        'contribution',
        'administrative_expense',
        'administrative_fee',
        'operational_deduction',
        'daily_total',
        'fund_balance',
        'administrative_debt',
        'accumulated_administrative_debt',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'journal_date' => 'date',
            'daily_income' => 'decimal:2',
            'daily_expense' => 'decimal:2',
            'contribution' => 'decimal:2',
            'administrative_expense' => 'decimal:2',
            'administrative_fee' => 'decimal:2',
            'operational_deduction' => 'decimal:2',
            'daily_total' => 'decimal:2',
            'fund_balance' => 'decimal:2',
            'administrative_debt' => 'decimal:2',
            'accumulated_administrative_debt' => 'decimal:2',
        ];
    }

    protected static function newFactory(): DailyJournalEntryFactory
    {
        return DailyJournalEntryFactory::new();
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'uuid');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by', 'uuid');
    }

    public function incomeAmount(): float
    {
        return (float) ($this->daily_income ?? 0);
    }

    public function expenseAmount(): float
    {
        return (float) ($this->daily_expense ?? 0);
    }

    public function contributionAmount(): float
    {
        return (float) ($this->contribution ?? 0);
    }
}
