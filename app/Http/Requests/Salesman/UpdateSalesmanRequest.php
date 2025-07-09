<?php

namespace App\Http\Requests\Salesman;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSalesmanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => 'sometimes|string',
            'name' => 'sometimes|string',
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
}
