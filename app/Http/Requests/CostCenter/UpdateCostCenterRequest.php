<?php

namespace App\Http\Requests\CostCenter;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCostCenterRequest extends FormRequest
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
                Rule::unique('cost_centers')->ignore($this->cost_center),
            ],
            'name' => 'required|string|max:255',
            'sub_cost_center_of' => 'nullable|exists:cost_centers,id',
            'is_inactive' => 'boolean',
        ];
    }

    public function messages()
    {
        return [
            'code.required' => 'The cost center code is required.',
            'code.unique' => 'This cost center code is already in use.',
            'name.required' => 'The cost center name is required.',
            'sub_cost_center_of.exists' => 'The selected parent cost center does not exist.',
        ];
    }
}
