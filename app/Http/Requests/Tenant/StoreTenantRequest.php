<?php

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;

class StoreTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255|unique:tenants,name',
            'email' => 'required|email|unique:tenants,email',
            'domain' => 'required|string|alpha_dash|unique:domains,domain',
            'password' => 'required|string|min:8',
        ];
    }
}
