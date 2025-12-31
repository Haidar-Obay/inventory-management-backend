<?php

namespace App\Http\Requests\CustomerGroup;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCustomerGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['sometimes', 'string', 'max:255', Rule::unique('customer_groups', 'code')->ignore($this->route('customer_group'))],
            'name' => 'sometimes|string|max:255',
            'active' => 'sometimes|boolean',
        ];
    }
}
