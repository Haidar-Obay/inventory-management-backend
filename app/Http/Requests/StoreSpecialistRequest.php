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
            'capacity_per_hour' => ['nullable', 'integer', 'min:0'],
            'capacity_per_day' => ['nullable', 'integer', 'min:0'],
            'phone_1' => ['nullable', 'string', 'max:255'],
            'phone_2' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string'],
            'email' => ['nullable', 'email', 'max:255'],
            'speciality_ids' => ['array'],
            'speciality_ids.*' => ['integer', 'exists:specialities,id'],
            'asset_ids' => ['array'],
            'asset_ids.*' => ['integer', 'exists:assets,id'],
        ];
    }
}
