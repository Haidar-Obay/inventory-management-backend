<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAssociationServicePriceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'association_id' => ['sometimes', 'integer', 'exists:associations,id'],
            'service_id' => ['sometimes', 'integer', 'exists:services,id'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'discount' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}


