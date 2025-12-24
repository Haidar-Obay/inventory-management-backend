<?php

namespace App\Http\Requests\PaymentTerm;

use Illuminate\Foundation\Http\FormRequest;

class StorePaymentTermRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => 'required|string|max:255|unique:payment_terms,code',
            'name' => 'required|string|max:255|unique:payment_terms,name',
            'nb_days' => 'required|integer|min:0',
            'active' => 'sometimes|boolean',
        ];
    }
}

