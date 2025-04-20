<?php

namespace App\Http\Requests\Salesman;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSalesmanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $salesmanId = $this->route('salesman');

        return [
            'name' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('salesmen', 'name')->ignore($salesmanId),
            ],
            'address' => 'nullable|string',
            'phone1' => [
                'nullable',
                'string',
                'max:20',
                Rule::unique('salesmen', 'phone1')->ignore($salesmanId),
            ],
            'phone2' => [
                'nullable',
                'string',
                'max:20',
                Rule::unique('salesmen', 'phone2')->ignore($salesmanId),
            ],
            'email' => [
                'nullable',
                'email',
                'max:255',
                Rule::unique('salesmen', 'email')->ignore($salesmanId),
            ],
            'fix_commission' => 'nullable|numeric|min:0',
            'is_inactive' => 'nullable|boolean',
        ];
    }
}
