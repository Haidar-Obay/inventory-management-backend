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
            'code' => 'required|string|max:50|unique:payment_methods,code',
            'name' => 'required|string|max:255|unique:payment_methods,name',
            'is_credit_card' => 'required|boolean',
            'is_online_payment' => 'required|boolean',
            'active' => 'required|boolean',
        ];
    }
}
