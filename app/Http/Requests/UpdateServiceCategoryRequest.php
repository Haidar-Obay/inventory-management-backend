<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateServiceCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $routeCategory = request()->route('service_category');
        $categoryId = is_object($routeCategory) ? ($routeCategory->id ?? null) : $routeCategory;

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('service_categories', 'name')->ignore($categoryId)],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }
}