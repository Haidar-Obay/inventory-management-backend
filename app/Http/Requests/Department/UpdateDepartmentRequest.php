<?php

namespace App\Http\Requests\Department;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDepartmentRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('departments')->ignore($this->department),
            ],
            'name' => 'required|string|max:255',
            'sub_department_of' => 'nullable|exists:departments,id',
            'active' => 'boolean',
        ];
    }

    public function messages()
    {
        return [
            'code.required' => 'The department code is required.',
            'code.unique' => 'This department code is already in use.',
            'name.required' => 'The department name is required.',
            'sub_department_of.exists' => 'The selected parent department does not exist.',
        ];
    }
}
