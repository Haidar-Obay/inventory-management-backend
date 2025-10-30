<?php

namespace App\Http\Requests\Item;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSupplierPivotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'original_code' => 'nullable|string|max:255',
            'currency' => 'nullable|string|max:3',
            'cost' => 'nullable|numeric|min:0',
            'is_primary' => 'nullable|boolean',
        ];
    }
}
