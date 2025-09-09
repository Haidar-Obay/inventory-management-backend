<?php

namespace App\Http\Requests\Role;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRoleRequest extends FormRequest
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
        $routeRole = request()->route('role');
        $roleId = is_object($routeRole) ? ($routeRole->id ?? null) : $routeRole;

        return [
            'name' => [
                'required', 
                'string', 
                'max:100', 
                Rule::unique('roles', 'name')->ignore($roleId)
            ],
            'description' => ['nullable', 'string'],
            'active' => ['boolean'],
            // Optional bulk permissions payload
            'permissions' => ['sometimes', 'array'],
            'permissions.*.permission_id' => ['required', 'integer', 'exists:permissions,id'],
            'permissions.*.can_view' => ['sometimes', 'boolean'],
            'permissions.*.can_add' => ['sometimes', 'boolean'],
            'permissions.*.can_edit' => ['sometimes', 'boolean'],
            'permissions.*.can_delete' => ['sometimes', 'boolean'],
            // Optional sync flag (default true in controller)
            'sync' => ['sometimes', 'boolean'],
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
            'name.required' => 'The role name is required.',
            'name.unique' => 'A role with this name already exists.',
            'name.max' => 'The role name may not be greater than 100 characters.',
        ];
    }
}
