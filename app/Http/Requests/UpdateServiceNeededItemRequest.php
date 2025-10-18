<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateServiceNeededItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'service_id' => ['sometimes', 'integer', 'exists:services,id'],
            'item_id' => ['sometimes', 'integer', 'exists:items,id'],
            'quantity' => ['sometimes', 'numeric', 'min:0'],
        ];
    }
}
