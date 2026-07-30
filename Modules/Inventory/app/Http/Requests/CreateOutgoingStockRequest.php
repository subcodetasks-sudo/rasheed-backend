<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Inventory\Enums\InventoryExpenseType;

class CreateOutgoingStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'inventory_item_id' => ['required', 'integer', Rule::exists('inventory_items', 'id')],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'beneficiary_project_id' => ['required', 'integer', Rule::exists('projects', 'id')],
            'expense_type' => ['required', 'string', Rule::enum(InventoryExpenseType::class)],
            'notes' => ['nullable', 'string'],
            'movement_date' => ['prohibited'],
            'total_cost' => ['prohibited'],
            'unit_price' => ['prohibited'],
        ];
    }
}
