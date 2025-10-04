<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreServiceNeededItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'asset_id' => ['required', 'integer', 'exists:assets,id'],
            'description' => ['nullable', 'string', 'max:255'],
            'unit' => ['nullable', 'string', 'max:50'],
            'qty' => ['required', 'numeric', 'min:0'],
            'notes_multiline' => ['nullable', 'string'],
        ];
    }
}
