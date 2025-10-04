<?php

namespace App\Http\Requests\Permission;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePermissionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'resource_key' => [
                'required',
                'string',
                'max:100',
                Rule::unique('permissions', 'resource_key')->ignore($this->route('permission')),
            ],
            'resource_label' => ['required', 'string', 'max:150'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'resource_key.required' => 'The resource key is required.',
            'resource_key.unique' => 'A permission with this resource key already exists.',
            'resource_key.max' => 'The resource key may not be greater than 100 characters.',
            'resource_label.required' => 'The resource label is required.',
            'resource_label.max' => 'The resource label may not be greater than 150 characters.',
        ];
    }
}
