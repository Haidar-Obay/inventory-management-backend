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
        $paymentMethodId = $this->route('payment_method');

        return [
            'code' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('payment_methods', 'code')->ignore($paymentMethodId),
            ],
            'is_credit_card' => 'nullable|boolean',
            'is_online_payment' => 'nullable|boolean',
            'active' => 'nullable|boolean',
        ];
    }
}
