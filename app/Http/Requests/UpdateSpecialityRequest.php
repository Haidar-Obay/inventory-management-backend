<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSpecialityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $routeSpeciality = request()->route('speciality');
        $id = is_object($routeSpeciality) ? ($routeSpeciality->id ?? null) : $routeSpeciality;
        return [
            'name' => ['required', 'string', 'max:255', 'unique:specialities,name,' . $id],
        ];
    }
}


