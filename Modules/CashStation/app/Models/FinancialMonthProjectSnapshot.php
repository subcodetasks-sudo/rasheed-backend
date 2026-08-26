<?php

namespace Modules\CashStation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Project\Models\Project;

class FinancialMonthProjectSnapshot extends Model
{
    protected $table = 'financial_month_project_snapshots';

    protected $fillable = [
        'year',
        'month',
        'project_id',
        'project_name',
        'project_type',
        'project_status',
        'previous_monthly_total',
        'monthly_revenue',
        'monthly_expenses',
        'administrative_percentage',
        'operational_deduction',
        'monthly_total',
        'cs_administrative_debt',
        'remaining_administrative_debt',
        'cs_added_contribution',
        'cs_deducted_contribution',
        'net_cash_fund',
        'cash_status',
        'fund_balance_tip',
        'ms_administrative_debt',
        'ms_added_contribution',
        'ms_deducted_contribution',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'project_id' => 'integer',
            'previous_monthly_total' => 'decimal:2',
            'monthly_revenue' => 'decimal:2',
            'monthly_expenses' => 'decimal:2',
            'administrative_percentage' => 'decimal:2',
            'operational_deduction' => 'decimal:2',
            'monthly_total' => 'decimal:2',
            'cs_administrative_debt' => 'decimal:2',
            'remaining_administrative_debt' => 'decimal:2',
            'cs_added_contribution' => 'decimal:2',
            'cs_deducted_contribution' => 'decimal:2',
            'net_cash_fund' => 'decimal:2',
            'fund_balance_tip' => 'decimal:2',
            'ms_administrative_debt' => 'decimal:2',
            'ms_added_contribution' => 'decimal:2',
            'ms_deducted_contribution' => 'decimal:2',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
