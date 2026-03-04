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

        // Get available currency codes from central database
        $availableCurrencyCodes = tenancy()->central(function () {
            return \App\Models\AvailableCurrency::where('is_active', true)
                ->pluck('code')
                ->toArray();
        });

        return [
            // Company Information
            'company_name' => 'required|string|max:255',
            'location' => 'required|string|max:255',

            // Language & Localization
            'main_language' => ['required', Rule::in(['en', 'ar'])],
            'preferred_mode' => ['required', Rule::in(['light', 'dark'])],
            'time_format' => ['required', Rule::in(['12', '24'])],

            // Currency Settings - New format
            'selected_currencies' => [
                'required',
                'array',
                'min:1',
                'max:'.$maxCurrencies,
            ],
            'selected_currencies.*' => [
                'required',
                'string',
                Rule::in($availableCurrencyCodes),
            ],
            'primary_currency_code' => [
                'required',
                'string',
                Rule::in($availableCurrencyCodes),
                function ($attribute, $value, $fail) {
                    $selectedCurrencies = $this->input('selected_currencies', []);
                    if (! in_array($value, $selectedCurrencies)) {
                        $fail('The primary currency must be one of the selected currencies.');
                    }
                },
            ],
            'currency_pairs' => 'nullable|array',
            'currency_pairs.*.from_code' => ['nullable', 'string', Rule::in($availableCurrencyCodes)],
            'currency_pairs.*.to_code' => ['nullable', 'string', Rule::in($availableCurrencyCodes)],
            'currency_pairs.*.rate' => 'nullable|numeric|min:0.0001',

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
        $tenant = tenant();
        $plan = $tenant?->subscriptionPlan;
        $maxCurrencies = $plan?->max_currencies ?? 1;

        return [
            'company_name.required' => 'Company name is required.',
            'location.required' => 'Location is required.',
            'selected_currencies.required' => 'Please select at least one currency.',
            'selected_currencies.array' => 'Selected currencies must be an array.',
            'selected_currencies.min' => 'Please select at least one currency.',
            'selected_currencies.max' => "You can select a maximum of {$maxCurrencies} currency(ies) based on your subscription plan.",
            'selected_currencies.*.required' => 'Each currency code is required.',
            'selected_currencies.*.in' => 'One or more selected currencies are not available.',
            'primary_currency_code.required' => 'Primary currency is required.',
            'primary_currency_code.in' => 'The selected primary currency is not available.',
            'working_time_from.required' => 'Working time start is required.',
            'working_time_to.required' => 'Working time end is required.',
            'working_time_to.after' => 'Working time end must be after working time start.',
            'days_off.*.in' => 'Invalid day name in days off list.',
        ];
    }
}
