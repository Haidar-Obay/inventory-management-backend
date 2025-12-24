<?php

namespace App\Http\Requests\SupplierGroup;

use Illuminate\Foundation\Http\FormRequest;

class StoreSupplierGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => 'required|string|max:255|unique:supplier_groups,code',
            'name' => 'required|string|max:255',
            'active' => 'sometimes|boolean',
        ];
    }
}

