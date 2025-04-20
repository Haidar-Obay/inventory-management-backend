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
        ];
    }
}
