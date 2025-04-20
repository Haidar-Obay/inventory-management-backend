<?php

namespace App\Http\Requests\ReferBy;

use Illuminate\Foundation\Http\FormRequest;

class StoreReferByRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255|unique:refer_bies,name',
            'address' => 'nullable|string',
            'phone1' => 'nullable|string|max:20|unique:refer_bies,phone1',
            'phone2' => 'nullable|string|max:20|unique:refer_bies,phone2',
            'email' => 'nullable|email|max:255|unique:refer_bies,email',
            'fix_commission' => 'nullable|numeric|min:0',
        ];
    }
}
