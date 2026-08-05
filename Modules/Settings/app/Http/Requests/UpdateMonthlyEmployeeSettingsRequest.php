<?php

namespace Modules\Settings\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Settings\Support\MonthlyEmployeeCategories;

class UpdateMonthlyEmployeeSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $categoryRules = [];
        foreach (MonthlyEmployeeCategories::KEYS as $key) {
            $categoryRules[$key] = ['sometimes', 'numeric', 'min:0'];
        }

        return array_merge([
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
        ], $categoryRules);
    }

    public function month(): int
    {
        return (int) $this->validated('month');
    }

    public function year(): int
    {
        return (int) $this->validated('year');
    }

    /**
     * @return array<string, float|int|string>
     */
    public function categories(): array
    {
        $validated = $this->validated();
        $categories = [];

        foreach (MonthlyEmployeeCategories::KEYS as $key) {
            $categories[$key] = $validated[$key] ?? 0;
        }

        return $categories;
    }
}
