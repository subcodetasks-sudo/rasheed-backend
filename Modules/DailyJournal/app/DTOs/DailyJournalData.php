<?php

namespace Modules\DailyJournal\DTOs;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

readonly class DailyJournalEntryInput
{
    public function __construct(
        public int $projectId,
        public ?float $dailyIncome,
        public ?float $dailyExpense,
        public ?float $contribution,
        public bool $hasDailyIncome = true,
        public bool $hasDailyExpense = true,
        public bool $hasContribution = true,
    ) {}
}

readonly class DailyJournalData
{
    /**
     * @param  list<DailyJournalEntryInput>  $entries
     */
    public function __construct(
        public CarbonInterface $journalDate,
        public array $entries,
    ) {}

    public static function fromArray(array $data): self
    {
        $journalDate = isset($data['journal_date']) && $data['journal_date'] !== null && $data['journal_date'] !== ''
            ? Carbon::parse($data['journal_date'])->startOfDay()
            : now()->startOfDay();

        $entries = [];

        foreach ($data['entries'] ?? [] as $entry) {
            $entries[] = new DailyJournalEntryInput(
                projectId: (int) $entry['project_id'],
                dailyIncome: array_key_exists('daily_income', $entry) && $entry['daily_income'] !== null
                    ? (float) $entry['daily_income']
                    : null,
                dailyExpense: array_key_exists('daily_expense', $entry) && $entry['daily_expense'] !== null
                    ? (float) $entry['daily_expense']
                    : null,
                contribution: array_key_exists('contribution', $entry) && $entry['contribution'] !== null
                    ? (float) $entry['contribution']
                    : null,
                hasDailyIncome: array_key_exists('daily_income', $entry),
                hasDailyExpense: array_key_exists('daily_expense', $entry),
                hasContribution: array_key_exists('contribution', $entry),
            );
        }

        return new self(journalDate: $journalDate, entries: $entries);
    }

    public function hasPositiveContribution(): bool
    {
        foreach ($this->entries as $entry) {
            if ($this->isPositiveContribution($entry)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Force positive contributions to null for Pass 1 (deficit detection without contribution).
     */
    public function withPositiveContributionsZeroed(): self
    {
        $entries = [];

        foreach ($this->entries as $entry) {
            if (! $this->isPositiveContribution($entry)) {
                $entries[] = $entry;

                continue;
            }

            $entries[] = new DailyJournalEntryInput(
                projectId: $entry->projectId,
                dailyIncome: $entry->dailyIncome,
                dailyExpense: $entry->dailyExpense,
                contribution: null,
                hasDailyIncome: $entry->hasDailyIncome,
                hasDailyExpense: $entry->hasDailyExpense,
                hasContribution: true,
            );
        }

        return new self(journalDate: $this->journalDate, entries: $entries);
    }

    private function isPositiveContribution(DailyJournalEntryInput $entry): bool
    {
        return $entry->hasContribution
            && $entry->contribution !== null
            && (float) $entry->contribution > 0;
    }
}
