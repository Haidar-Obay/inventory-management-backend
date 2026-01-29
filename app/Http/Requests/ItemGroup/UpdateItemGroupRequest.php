<?php

namespace App\Http\Requests\ItemGroup;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateItemGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['sometimes', 'string', 'max:255', Rule::unique('item_groups', 'code')->ignore($this->route('item_group'))],
            'name' => 'sometimes|string|max:255',
            'active' => 'sometimes|boolean',
        ];
    }
}
