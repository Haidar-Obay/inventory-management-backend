<?php

namespace App\Http\Requests\Section;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'room_id' => 'sometimes|exists:rooms,id',
            'name' => 'sometimes|string|max:255',
            'order_index' => 'sometimes|integer|min:0',
        ];
    }

    public function messages(): array
    {
        return [
            'room_id.exists' => 'The selected room does not exist.',
            'name.string' => 'The section name must be a string.',
            'name.max' => 'The section name cannot exceed 255 characters.',
            'order_index.integer' => 'The order index must be an integer.',
            'order_index.min' => 'The order index must be at least 0.',
        ];
    }
}
