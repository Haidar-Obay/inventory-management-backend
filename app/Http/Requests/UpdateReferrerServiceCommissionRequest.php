<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateReferrerServiceCommissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'referrer_id' => ['sometimes', 'integer', 'exists:referrers,id'],
            'service_id' => ['sometimes', 'integer', 'exists:services,id'],
            'price_override' => ['nullable', 'numeric', 'min:0'],
            'discount_override' => ['nullable', 'numeric', 'min:0'],
            'commission_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }
}


