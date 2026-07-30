<?php

namespace Modules\Project\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Project\Database\Factories\ProjectFactory;
use Modules\Project\Enums\FundType;
use Modules\Project\Enums\OperationalDeductionType;
use Modules\Project\Enums\ProjectStatus;
use Modules\User\app\Models\User;

class Project extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'category_id',
        'fund_type',
        'status',
        'operational_deduction_type',
        'operational_fixed_amount',
        'administrative_exempt',
        'administrative_fee_percentage',
        'archived_at',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'fund_type' => FundType::class,
            'status' => ProjectStatus::class,
            'operational_deduction_type' => OperationalDeductionType::class,
            'operational_fixed_amount' => 'decimal:2',
            'administrative_exempt' => 'boolean',
            'administrative_fee_percentage' => 'decimal:2',
            'archived_at' => 'datetime',
        ];
    }

    protected static function newFactory(): ProjectFactory
    {
        return ProjectFactory::new();
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'uuid');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by', 'uuid');
    }

    public function isActive(): bool
    {
        return $this->status === ProjectStatus::Active;
    }

    public function isStopped(): bool
    {
        return $this->status === ProjectStatus::Stopped;
    }

    public function isArchived(): bool
    {
        return $this->status === ProjectStatus::Archived || $this->archived_at !== null;
    }

    public function isOperationalExempt(): bool
    {
        return $this->operational_deduction_type === OperationalDeductionType::Exempt;
    }

    public function hasFixedOperationalDeduction(): bool
    {
        return $this->operational_deduction_type === OperationalDeductionType::Fixed;
    }

    public function hasRelativeOperationalDeduction(): bool
    {
        return $this->operational_deduction_type === OperationalDeductionType::Relative;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', ProjectStatus::Active);
    }

    public function scopeFixed(Builder $query): Builder
    {
        return $query->where('fund_type', FundType::Fixed);
    }

    public function scopeVariable(Builder $query): Builder
    {
        return $query->where('fund_type', FundType::Variable);
    }

    public function scopeArchived(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->where('status', ProjectStatus::Archived)
                ->orWhereNotNull('archived_at');
        });
    }

    public function scopeNotArchived(Builder $query): Builder
    {
        return $query->where('status', '!=', ProjectStatus::Archived)
            ->whereNull('archived_at');
    }

    public function scopeOperationalParticipating(Builder $query): Builder
    {
        return $query->where('status', ProjectStatus::Active)
            ->where('operational_deduction_type', '!=', OperationalDeductionType::Exempt);
    }

    public function scopeRelativeDeduction(Builder $query): Builder
    {
        return $query->where('operational_deduction_type', OperationalDeductionType::Relative);
    }
}
