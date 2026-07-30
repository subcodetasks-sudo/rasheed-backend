<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateInventoryItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:255'],
            'project_id' => ['required', 'integer', Rule::exists('projects', 'id')],
            'unit' => ['required', 'string', 'max:50'],
            'opening_price' => ['required', 'numeric', 'min:0'],
            'opening_quantity' => ['required', 'numeric', 'min:0'],
            'minimum_stock_level' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'code' => ['prohibited'],
            'current_balance' => ['prohibited'],
            'total_incoming_quantity' => ['prohibited'],
            'total_outgoing_quantity' => ['prohibited'],
            'latest_incoming_price' => ['prohibited'],
        ];
    }
}
