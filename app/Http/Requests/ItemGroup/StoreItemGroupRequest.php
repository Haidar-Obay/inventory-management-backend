<?php

namespace App\Http\Requests\ItemGroup;

use Illuminate\Foundation\Http\FormRequest;

class StoreItemGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => 'required|string|max:255|unique:item_groups,code',
            'name' => 'required|string|max:255',
            'active' => 'sometimes|boolean',
        ];
    }
}
