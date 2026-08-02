<?php

namespace Modules\Dashboard\Actions;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\InventoryItem;

class BuildDashboardSummaryAction
{
    public function execute(CarbonInterface $journalDate): array
    {
        $dateString = $journalDate->toDateString();

        $allProjects = DB::table('daily_journal_entries')
            ->whereDate('journal_date', $dateString)
            ->selectRaw('COALESCE(SUM(COALESCE(daily_income, 0)), 0) as total_daily_income')
            ->selectRaw('COALESCE(SUM(COALESCE(daily_expense, 0)), 0) as total_daily_expenses')
            ->selectRaw('COALESCE(SUM(COALESCE(operational_deduction, 0)), 0) as total_operational_deduction')
            ->first();

        $administrativePercentage = DB::table('daily_journal_entries')
            ->join('projects', 'daily_journal_entries.project_id', '=', 'projects.id')
            ->where('projects.administrative_exempt', false)
            ->whereDate('daily_journal_entries.journal_date', $dateString)
            ->selectRaw('COALESCE(SUM(COALESCE(daily_journal_entries.administrative_fee, 0)), 0) as total_administrative_percentage')
            ->value('total_administrative_percentage');

        $lowStockItems = InventoryItem::query()
            ->whereColumn('current_balance', '<=', 'minimum_stock_level')
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'current_balance', 'minimum_stock_level', 'project_id'])
            ->map(fn (InventoryItem $item) => [
                'id' => $item->id,
                'code' => $item->code,
                'name' => $item->name,
                'current_balance' => $item->current_balance,
                'minimum_stock_level' => $item->minimum_stock_level,
                'project_id' => $item->project_id,
            ])
            ->values()
            ->all();

        return [
            'total_daily_income' => $this->decimal($allProjects->total_daily_income ?? 0),
            'total_daily_expenses' => $this->decimal($allProjects->total_daily_expenses ?? 0),
            'total_administrative_percentage' => $this->decimal($administrativePercentage ?? 0),
            'total_operational_deduction' => $this->decimal($allProjects->total_operational_deduction ?? 0),
            'low_stock_items' => $lowStockItems,
        ];
    }

    private function decimal(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
