<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSpecialistRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $routeSpecialist = request()->route('specialist');
        $id = is_object($routeSpecialist) ? ($routeSpecialist->id ?? null) : $routeSpecialist;
        return [
            'name' => ['required', 'string', 'max:255', 'unique:specialists,name,' . $id],
            'speciality_ids' => ['array'],
            'speciality_ids.*' => ['integer', 'exists:specialities,id'],
            'asset_ids' => ['array'],
            'asset_ids.*' => ['integer', 'exists:assets,id'],
        ];
    }
}


