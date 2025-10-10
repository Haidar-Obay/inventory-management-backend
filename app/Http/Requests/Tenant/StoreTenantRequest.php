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
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:tenants,email',
            'domain' => 'required|string|unique:tenants,id',
            'password' => 'required|string|min:8',
            'subscription_plan_id' => 'nullable|exists:subscription_plans,id',
            'subscription_start_date' => 'nullable|date',
            'subscription_end_date' => 'nullable|date',
            'subscription_status' => 'nullable|in:active,expired,cancelled,trial',
            'auto_renew' => 'nullable|boolean',
            'data' => 'nullable|array',

            // modules assignment at creation
            'selected_modules' => 'nullable|array',
            'selected_modules.*' => 'integer|exists:modules,id',
        ];
    }
}
