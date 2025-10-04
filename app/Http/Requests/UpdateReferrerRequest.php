<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateReferrerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $routeModel = request()->route('referrer');
        $id = is_object($routeModel) ? ($routeModel->id ?? null) : $routeModel;

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('referrers', 'name')->ignore($id)],
            'address' => ['nullable', 'string', 'max:255'],
            'phone1' => ['nullable', 'string', 'max:50'],
            'phone2' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'active' => ['boolean'],
            'commission_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
