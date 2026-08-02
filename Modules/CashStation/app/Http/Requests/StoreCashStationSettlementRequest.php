<?php

namespace Modules\CashStation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCashStationSettlementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'from_project_id' => ['required', 'integer', 'exists:projects,id', 'different:to_project_id'],
            'to_project_id' => ['required', 'integer', 'exists:projects,id'],
            'amount' => ['required', 'numeric', 'gt:0'],
        ];
    }

    public function month(): int
    {
        return (int) $this->validated('month');
    }

    public function year(): int
    {
        return (int) $this->validated('year');
    }

    public function fromProjectId(): int
    {
        return (int) $this->validated('from_project_id');
    }

    public function toProjectId(): int
    {
        return (int) $this->validated('to_project_id');
    }

    public function amount(): string
    {
        return (string) $this->validated('amount');
    }
}
