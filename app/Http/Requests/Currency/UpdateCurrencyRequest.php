<?php

namespace App\Http\Requests\Currency;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCurrencyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $currencyId = $this->route('currency');

        return [
            'name' => 'nullable|string|max:255',
            'code' => [
                'nullable',
                'string',
                'max:10',
                Rule::unique('currencies', 'code')->ignore($currencyId),
            ],
            'iso_code' => [
                'nullable',
                'string',
                'max:10',
                Rule::unique('currencies', 'iso_code')->ignore($currencyId),
            ],
            'rate' => 'nullable|numeric|min:0',
            'smallest_unit' => 'nullable|numeric|min:0',
            'round_limit' => 'nullable|numeric|min:0',
            'acceptable_amount_overdue' => 'nullable|numeric|min:0',
            'allowed_difference_in_receipt' => 'nullable|numeric|min:0',
            'allowed_difference_in_payment' => 'nullable|numeric|min:0',
            'active' => 'nullable|boolean',
            'is_primary' => 'nullable|boolean',
        ];
    }
}
