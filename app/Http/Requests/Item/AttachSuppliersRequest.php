<?php

namespace App\Http\Requests\Item;

use Illuminate\Foundation\Http\FormRequest;

class AttachSuppliersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'suppliers' => 'required|array|min:1',
            'suppliers.*.supplier_id' => 'required|integer|exists:suppliers,id',
            'suppliers.*.original_code' => 'nullable|string|max:255',
            'suppliers.*.currency' => 'nullable|string|max:3',
            'suppliers.*.cost' => 'nullable|numeric|min:0',
            'suppliers.*.is_primary' => 'nullable|boolean',
        ];
    }
}
