<?php

namespace App\Http\Requests\SupplierGroup;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSupplierGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['sometimes', 'string', 'max:255', Rule::unique('supplier_groups', 'code')->ignore($this->route('supplier_group'))],
            'name' => 'sometimes|string|max:255',
            'active' => 'sometimes|boolean',
        ];
    }
}

