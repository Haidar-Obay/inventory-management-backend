<?php

namespace App\Http\Requests\Currency;

use Illuminate\Foundation\Http\FormRequest;

class StoreCurrencyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:10|unique:currencies,code',
            'iso_code' => 'required|string|max:10|unique:currencies,iso_code',
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
