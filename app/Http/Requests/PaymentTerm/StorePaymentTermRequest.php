<?php

namespace App\Http\Requests\PaymentTerm;

use App\Models\PaymentTerm;
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
            'primary' => [
                'sometimes',
                'boolean',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value && PaymentTerm::where('primary', true)->exists()) {
                        $fail('Only one payment term can be set as primary.');
                    }
                },
            ],
        ];
    }
}
