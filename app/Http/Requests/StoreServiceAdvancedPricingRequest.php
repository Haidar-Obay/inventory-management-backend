<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreServiceAdvancedPricingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'specialist_id' => ['required', 'integer', 'exists:specialists,id'],
            'price_on_site' => ['required', 'numeric', 'min:0'],
            'price_on_call' => ['required', 'numeric', 'min:0'],
        ];
    }
}


