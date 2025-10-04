<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreReferrerServiceCommissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'referrer_id' => ['required', 'integer', 'exists:referrers,id'],
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'price_override' => ['nullable', 'numeric', 'min:0'],
            'discount_override' => ['nullable', 'numeric', 'min:0'],
            'commission_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
