<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAssociationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'unique:associations,name'],
            'phone1' => ['nullable', 'string', 'max:50'],
            'phone2' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'website' => ['nullable', 'string', 'max:255'],
            'markup_value' => ['nullable', 'numeric', 'min:0'],
            'markup_type' => ['nullable', Rule::in(['percent', 'amount'])],
            'markdown_value' => ['nullable', 'numeric', 'min:0'],
            'markdown_type' => ['nullable', Rule::in(['percent', 'amount'])],
            'allowed_to_pay_for_guests' => ['boolean'],
            'active' => ['boolean'],
        ];
    }
}
