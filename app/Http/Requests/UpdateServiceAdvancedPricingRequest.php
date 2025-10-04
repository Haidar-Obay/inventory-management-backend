<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateServiceAdvancedPricingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'service_id' => ['sometimes', 'integer', 'exists:services,id'],
            'specialist_id' => ['sometimes', 'integer', 'exists:specialists,id'],
            'price_on_site' => ['sometimes', 'numeric', 'min:0'],
            'price_on_call' => ['sometimes', 'numeric', 'min:0'],
        ];
    }
}
