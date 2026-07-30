<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ListInventoryMovementsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'filter' => ['nullable', 'array'],
            'filter.inventory_item_id' => ['nullable', 'integer'],
            'filter.type' => ['nullable', 'string'],
            'filter.expense_type' => ['nullable', 'string'],
            'filter.beneficiary_project_id' => ['nullable', 'integer'],
            'sort' => ['nullable', 'string'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
