<?php

namespace App\Http\Requests\ReferBy;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateReferByRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $referById = $this->route('refer_by'); // Make sure this matches your route parameter name

        return [
            'name' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('refer_bies', 'name')->ignore($referById),
            ],
            'address' => 'nullable|string',
            'phone1' => [
                'nullable',
                'string',
                'max:20',
                Rule::unique('refer_bies', 'phone1')->ignore($referById),
            ],
            'phone2' => [
                'nullable',
                'string',
                'max:20',
                Rule::unique('refer_bies', 'phone2')->ignore($referById),
            ],
            'email' => [
                'nullable',
                'email',
                'max:255',
                Rule::unique('refer_bies', 'email')->ignore($referById),
            ],
            'fix_commission' => 'nullable|numeric|min:0',
        ];
    }
}
