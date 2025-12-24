<?php

namespace App\Http\Requests\PaymentMethod;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePaymentMethodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:50', Rule::unique('payment_methods', 'code')->ignore($this->route('payment_method'))],
            'name' => ['required', 'string', 'max:255', Rule::unique('payment_methods', 'name')->ignore($this->route('payment_method'))],
            'is_credit_card' => 'sometimes|boolean',
            'is_online_payment' => 'sometimes|boolean',
            'active' => 'sometimes|boolean',
        ];
    }
}
