<?php

namespace App\Http\Requests\PaymentTerm;

use App\Models\PaymentTerm;
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
        $paymentTerm = $this->route('payment_term');

        return [
            'code' => ['sometimes', 'string', 'max:255', Rule::unique('payment_terms', 'code')->ignore($paymentTerm)],
            'name' => ['sometimes', 'string', 'max:255', Rule::unique('payment_terms', 'name')->ignore($paymentTerm)],
            'nb_days' => 'sometimes|integer|min:0',
            'active' => 'sometimes|boolean',
            'primary' => [
                'sometimes',
                'boolean',
                function (string $attribute, mixed $value, \Closure $fail) use ($paymentTerm): void {
                    if ($value && PaymentTerm::where('primary', true)->where('id', '!=', $paymentTerm?->id)->exists()) {
                        $fail('Only one payment term can be set as primary.');
                    }
                },
            ],
        ];
    }
}
