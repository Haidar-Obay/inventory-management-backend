<?php

namespace App\Http\Requests\UnitGroup;

use Illuminate\Foundation\Http\FormRequest;

class StoreUnitGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255|unique:unit_groups,name',
        ];
    }
}
