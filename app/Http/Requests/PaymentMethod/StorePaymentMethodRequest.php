<?php

namespace App\Http\Requests\PaymentMethod;

use Illuminate\Foundation\Http\FormRequest;

class StorePaymentMethodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => 'required|string|max:255|unique:payment_methods,code',
            'is_credit_card' => 'nullable|boolean',
            'is_online_payment' => 'nullable|boolean',
            'active' => 'nullable|boolean',
        ];
    }
}
