<?php

namespace Modules\Authorization\app\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'full_name' => 'required|string|max:255',
            'user_name' => 'required|string|max:255|unique:users,user_name',
            'email' => 'required|email|unique:users,email',
            'phone' => 'nullable|string|max:255',
            'password' => 'required|string|min:8',
            'role' => 'required|string|exists:roles,name',

        ];
    }
}
