<?php

namespace Modules\OperationalFund\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ShowOperationalFundDayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
        ];
    }

    public function fundDate(): string
    {
        return (string) $this->validated('date');
    }
}
