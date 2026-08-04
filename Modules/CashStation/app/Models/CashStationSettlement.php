<?php

namespace Modules\CashStation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\MonthlySummary\Enums\ContributionType;
use Modules\Project\Models\Project;
use Modules\User\app\Models\User;

class CashStationSettlement extends Model
{
    protected $table = 'cash_station_settlements';

    protected $fillable = [
        'year',
        'month',
        'from_project_id',
        'to_project_id',
        'amount',
        'contribution_type',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'amount' => 'decimal:2',
            'contribution_type' => ContributionType::class,
        ];
    }

    public function fromProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'from_project_id');
    }

    public function toProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'to_project_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'uuid');
    }
}
