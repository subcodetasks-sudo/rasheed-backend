<?php

namespace Modules\Project\DTOs;

use Modules\Project\Enums\FundType;
use Modules\Project\Enums\OperationalDeductionType;
use Modules\Project\Enums\ProjectStatus;

readonly class ProjectData
{
    public function __construct(
        public string $name,
        public int $categoryId,
        public FundType $fundType,
        public ProjectStatus $status,
        public OperationalDeductionType $operationalDeductionType,
        public ?float $operationalFixedAmount,
        public bool $administrativeExempt,
    ) {}

    public static function fromArray(array $data): self
    {
        $deductionType = OperationalDeductionType::from($data['operational_deduction_type']);

        return new self(
            name: $data['name'],
            categoryId: (int) $data['category_id'],
            fundType: FundType::from($data['fund_type']),
            status: ProjectStatus::from($data['status']),
            operationalDeductionType: $deductionType,
            operationalFixedAmount: $deductionType === OperationalDeductionType::Fixed
                ? (isset($data['operational_fixed_amount']) ? (float) $data['operational_fixed_amount'] : null)
                : null,
            administrativeExempt: (bool) ($data['administrative_exempt'] ?? false),
        );
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'category_id' => $this->categoryId,
            'fund_type' => $this->fundType,
            'status' => $this->status,
            'operational_deduction_type' => $this->operationalDeductionType,
            'operational_fixed_amount' => $this->operationalFixedAmount,
            'administrative_exempt' => $this->administrativeExempt,
        ];
    }
}
