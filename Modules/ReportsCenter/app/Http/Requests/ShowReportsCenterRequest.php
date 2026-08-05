<?php

namespace Modules\ReportsCenter\Http\Requests;

use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ShowReportsCenterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'period_type' => ['required', 'string', Rule::in(['month', 'custom'])],
            'month' => ['required_if:period_type,month', 'nullable', 'integer', 'min:1', 'max:12'],
            'year' => ['required_if:period_type,month', 'nullable', 'integer', 'min:2000', 'max:2100'],
            'start_date' => ['required_if:period_type,custom', 'nullable', 'date_format:Y-m-d'],
            'end_date' => ['required_if:period_type,custom', 'nullable', 'date_format:Y-m-d'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->input('period_type') !== 'custom') {
                return;
            }

            $start = $this->input('start_date');
            $end = $this->input('end_date');

            if (! is_string($start) || ! is_string($end) || $start === '' || $end === '') {
                return;
            }

            if ($start > $end) {
                $validator->errors()->add('end_date', 'The end date must be on or after the start date.');
            }
        });
    }

    /**
     * @return array{period_type: string, start_date: string, end_date: string, month?: int, year?: int}
     */
    public function period(): array
    {
        $periodType = (string) $this->validated('period_type');

        if ($periodType === 'month') {
            $month = (int) $this->validated('month');
            $year = (int) $this->validated('year');
            $start = Carbon::create($year, $month, 1)->startOfDay();

            return [
                'period_type' => 'month',
                'month' => $month,
                'year' => $year,
                'start_date' => $start->toDateString(),
                'end_date' => $start->copy()->endOfMonth()->toDateString(),
            ];
        }

        return [
            'period_type' => 'custom',
            'start_date' => (string) $this->validated('start_date'),
            'end_date' => (string) $this->validated('end_date'),
        ];
    }
}
