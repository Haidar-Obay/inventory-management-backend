<?php

namespace App\Http\Requests\PaymentTerm;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePaymentTermRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['sometimes', 'string', 'max:255', Rule::unique('payment_terms', 'code')->ignore($this->route('payment_term'))],
            'name' => ['sometimes', 'string', 'max:255', Rule::unique('payment_terms', 'name')->ignore($this->route('payment_term'))],
            'nb_days' => 'sometimes|integer|min:0',
            'active' => 'sometimes|boolean',
        ];
    }
}

