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
            'name' => 'required|string|max:255|unique:salesmen,name',
            'address' => 'nullable|string',
            'phone1' => 'nullable|string|max:20|unique:salesmen,phone1',
            'phone2' => 'nullable|string|max:20|unique:salesmen,phone2',
            'email' => 'nullable|email|max:255|unique:salesmen,email',
            'fix_commission' => 'nullable|numeric|min:0',
            'is_inactive' => 'nullable|boolean',
        ];
    }
}
