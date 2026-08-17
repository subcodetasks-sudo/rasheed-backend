<?php

namespace Modules\User\app\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $userUuid = $this->route('user')->uuid;

        return [
            'full_name' => 'sometimes|string|max:255',
            'name' => 'sometimes|string|max:255',
            'user_name' => 'sometimes|string|max:255|unique:users,user_name,'.$userUuid.',uuid',
            'email' => 'sometimes|email|unique:users,email,'.$userUuid.',uuid',
            'phone' => 'sometimes|nullable|string|max:255',
            'password' => 'sometimes|string|min:8',
            'role' => 'nullable|string|exists:roles,name',
        ];
    }
}
