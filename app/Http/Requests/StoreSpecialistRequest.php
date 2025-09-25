<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSpecialistRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'unique:specialists,name'],
            'speciality_ids' => ['array'],
            'speciality_ids.*' => ['integer', 'exists:specialities,id'],
            'asset_ids' => ['array'],
            'asset_ids.*' => ['integer', 'exists:assets,id'],
        ];
    }
}


