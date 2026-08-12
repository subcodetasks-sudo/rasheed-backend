<?php

namespace Modules\AuditLog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\AuditLog\Enums\AuditAction;

class ListAuditLogsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'filter' => ['nullable', 'array'],
            'filter.user_id' => ['nullable', 'uuid'],
            'filter.action' => ['nullable', 'string', Rule::in(AuditAction::values())],
            'filter.created_from' => ['nullable', 'date'],
            'filter.created_to' => ['nullable', 'date', 'after_or_equal:filter.created_from'],
            'sort' => ['nullable', 'string'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
