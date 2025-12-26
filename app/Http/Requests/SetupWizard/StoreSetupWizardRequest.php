<?php

declare(strict_types=1);

namespace App\Http\Requests\SetupWizard;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSetupWizardRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // All authenticated users can access setup wizard
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $tenant = tenant();
        $plan = $tenant?->subscriptionPlan;
        $maxCurrencies = $plan?->max_currencies ?? 1;
        $supportsMultiCurrency = $maxCurrencies > 1;

        return [
            // Company Information
            'company_name' => 'required|string|max:255',
            'location' => 'required|string|max:255',

            // Language & Localization
            'main_language' => ['required', Rule::in(['en', 'ar'])],
            'preferred_mode' => ['required', Rule::in(['light', 'dark'])],
            'time_format' => ['required', Rule::in(['12', '24'])],

            // Currency Settings
            'primary_currency_id' => 'required|exists:currencies,id',
            'secondary_currency_id' => $supportsMultiCurrency
                ? ['nullable', 'exists:currencies,id', 'different:primary_currency_id']
                : 'prohibited',

            // Working Hours
            'working_time_from' => 'required|date_format:H:i',
            'working_time_to' => 'required|date_format:H:i|after:working_time_from',
            'days_off' => 'nullable|array',
            'days_off.*' => ['string', Rule::in(['saturday', 'sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday'])],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'company_name.required' => 'Company name is required.',
            'location.required' => 'Location is required.',
            'primary_currency_id.required' => 'Primary currency is required.',
            'primary_currency_id.exists' => 'Selected primary currency does not exist.',
            'secondary_currency_id.prohibited' => 'Your subscription plan does not support multiple currencies.',
            'secondary_currency_id.different' => 'Secondary currency must be different from primary currency.',
            'working_time_from.required' => 'Working time start is required.',
            'working_time_to.required' => 'Working time end is required.',
            'working_time_to.after' => 'Working time end must be after working time start.',
            'days_off.*.in' => 'Invalid day name in days off list.',
        ];
    }
}
