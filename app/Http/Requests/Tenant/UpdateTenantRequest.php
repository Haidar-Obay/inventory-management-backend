<?php

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = $this->route('id');

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('tenants', 'name')->ignore($tenantId),
            ],
            'email' => [
                'required',
                'email',
                Rule::unique('tenants', 'email')->ignore($tenantId),
            ],
            'domain' => [
                'nullable',
                'string',
                Rule::unique('domains', 'domain')->ignore($tenantId, 'tenant_id'),
            ],
            'password' => [
                'nullable',
                'string',
                'min:8',
            ],
        ];
    }
}
