<?php

namespace Modules\AdministrativeDebtSettlement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAdministrativeDebtSettlementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            'amount' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
        ];
    }

    public function year(): int
    {
        return (int) $this->validated('year');
    }

    public function month(): int
    {
        return (int) $this->validated('month');
    }

    public function projectId(): int
    {
        return (int) $this->validated('project_id');
    }

    public function amount(): ?float
    {
        $amount = $this->validated('amount') ?? null;

        return $amount === null ? null : (float) $amount;
    }
}
