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
            'code' => 'required|string|max:255|unique:salesmen,code',
            'name' => 'required|string|max:255',
            'address' => 'nullable|string',
            'phone1' => 'nullable|string|max:20',
            'phone2' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'is_manager' => 'boolean',
            'is_supervisor' => 'boolean',
            'is_collector' => 'boolean',
            'fix_commission' => 'nullable|numeric|min:0|max:999999.99',
            'commission_percent' => 'nullable|numeric|min:0|max:100',
            'commission_by_item' => 'nullable|numeric|min:0|max:999999.99',
            'commission_by_turnover' => 'nullable|numeric|min:0|max:999999.99',
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
            'commission_percent.numeric' => 'The commission percentage must be a number.',
            'commission_percent.min' => 'The commission percentage cannot be negative.',
            'commission_percent.max' => 'The commission percentage cannot exceed 100%.',
            'commission_by_item.numeric' => 'The commission by item must be a number.',
            'commission_by_item.min' => 'The commission by item cannot be negative.',
            'commission_by_turnover.numeric' => 'The commission by turnover must be a number.',
            'commission_by_turnover.min' => 'The commission by turnover cannot be negative.',
        ];
    }
}
