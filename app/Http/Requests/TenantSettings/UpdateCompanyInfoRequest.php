<?php

declare(strict_types=1);

namespace App\Http\Requests\TenantSettings;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCompanyInfoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Rules for company_info section (shared with setup wizard for consistency).
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'company_name' => 'required|string|max:255',
            'location' => 'required|string|max:255',
            'main_language' => ['required', Rule::in(['en', 'ar'])],
            'time_format' => ['required', Rule::in(['12', '24'])],
            'working_time_from' => 'required|date_format:H:i',
            'working_time_to' => 'required|date_format:H:i|after:working_time_from',
            'days_off' => 'nullable|array',
            'days_off.*' => ['string', Rule::in(['saturday', 'sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday'])],
        ];
    }

    public function messages(): array
    {
        return [
            'company_name.required' => 'Company name is required.',
            'location.required' => 'Location is required.',
            'working_time_from.required' => 'Working time start is required.',
            'working_time_from.date_format' => 'The working time from must match the format H:i (e.g. 09:00).',
            'working_time_to.required' => 'Working time end is required.',
            'working_time_to.date_format' => 'The working time to must match the format H:i (e.g. 17:00).',
            'working_time_to.after' => 'Working time end must be after working time start.',
            'days_off.*.in' => 'Invalid day name in days off list.',
        ];
    }
}
