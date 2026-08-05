<?php

namespace Modules\Settings\app\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Settings\Support\MonthlyEmployeeCategories;

class MonthlyEmployeeSetting extends Model
{
    protected $table = 'monthly_employee_settings';

    protected $fillable = [
        'year',
        'month',
        'fixed_workers',
        'media_staff',
        'administrative_staff',
        'variable_workers',
        'speakers',
        'cooks',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'fixed_workers' => 'decimal:2',
            'media_staff' => 'decimal:2',
            'administrative_staff' => 'decimal:2',
            'variable_workers' => 'decimal:2',
            'speakers' => 'decimal:2',
            'cooks' => 'decimal:2',
        ];
    }

    /**
     * @return array<string, float>
     */
    public function categoryAmounts(): array
    {
        $amounts = [];
        foreach (MonthlyEmployeeCategories::KEYS as $key) {
            $amounts[$key] = round((float) $this->{$key}, 2);
        }

        return $amounts;
    }

    public function relativeDeduction(): float
    {
        return MonthlyEmployeeCategories::sum($this->categoryAmounts());
    }
}
