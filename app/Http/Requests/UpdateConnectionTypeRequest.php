<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateConnectionTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $routeModel = request()->route('connection_type');
        $id = is_object($routeModel) ? ($routeModel->id ?? null) : $routeModel;
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('connection_types', 'name')->ignore($id)],
        ];
    }
}


