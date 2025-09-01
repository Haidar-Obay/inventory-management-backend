<?php

namespace App\Http\Requests\Section;

use Illuminate\Foundation\Http\FormRequest;

class StoreSectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'room_id' => 'required|exists:rooms,id',
            'name' => 'required|string|max:255',
            'order_index' => 'nullable|integer|min:0',
        ];
    }

    public function messages(): array
    {
        return [
            'room_id.required' => 'The room ID is required.',
            'room_id.exists' => 'The selected room does not exist.',
            'name.required' => 'The section name is required.',
            'name.string' => 'The section name must be a string.',
            'name.max' => 'The section name cannot exceed 255 characters.',
            'order_index.integer' => 'The order index must be an integer.',
            'order_index.min' => 'The order index must be at least 0.',
        ];
    }
}
