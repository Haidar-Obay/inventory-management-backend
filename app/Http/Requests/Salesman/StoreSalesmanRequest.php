<?php

namespace App\Http\Requests\Salesman;

use Illuminate\Foundation\Http\FormRequest;

class StoreSalesmanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => 'required|string|unique:salesmen,code',
            'name' => 'required|string',
            'address' => 'nullable|string',
            'phone1' => 'nullable|string',
            'phone2' => 'nullable|string',
            'email' => 'nullable|email',
            'is_manager' => 'boolean',
            'is_supervisor' => 'boolean',
            'is_collector' => 'boolean',
            'fix_commission' => 'nullable|numeric',
            'commission_by_item' => 'nullable|numeric',
            'active' => 'boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'code.required' => 'The salesman code is required.',
            'code.unique' => 'The salesman code must be unique.',
            'name.required' => 'The salesman name is required.',
            'email.email' => 'Please provide a valid email address.',
            'fix_commission.numeric' => 'The fixed commission must be a number.',
            'fix_commission.min' => 'The fixed commission cannot be negative.',
            'commission_by_item.numeric' => 'The commission by item must be a number.',
            'commission_by_item.min' => 'The commission by item cannot be negative.',
        ];
    }
}
