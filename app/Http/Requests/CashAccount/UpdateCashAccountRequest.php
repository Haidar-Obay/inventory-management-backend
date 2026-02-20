<?php

namespace App\Http\Requests\CashAccount;

use App\Enums\CashAccountType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCashAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $typeValues = array_column(CashAccountType::cases(), 'value');

        return [
            'name' => 'sometimes|string|max:255',
            'type' => ['sometimes', 'string', Rule::in($typeValues)],
            'currency_id' => 'nullable|exists:currencies,id',
            'opening_amount' => 'nullable|numeric',
            'opening_date' => 'nullable|date',
            'bank_name' => 'nullable|string|max:255',
            'branch' => 'nullable|string|max:255',
            'company_name' => 'nullable|string|max:255',
            'iban' => 'nullable|string|max:255',
            'swift' => 'nullable|string|max:255',
            'holder_name' => 'nullable|string|max:255',
            'number' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:255',
            'expiry_date' => 'nullable|string|max:50',
            'cvv' => 'nullable|string|max:10',
        ];
    }
}
