<?php

namespace App\Http\Requests\PaymentTerm;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePaymentTermRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'nullable|string|max:255',
            'no_of_days' => 'nullable|integer|min:1',
            'is_inactive' => 'nullable|boolean',
        ];
    }
}
