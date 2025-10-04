<?php

namespace App\Http\Requests\Room;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => [
                'sometimes',
                'string',
                'max:255',
            ],
            'location' => [
                'sometimes',
                'string',
                'max:255',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'name.string' => 'The room name must be a string.',
            'name.max' => 'The room name cannot exceed 255 characters.',
            'location.string' => 'The room location must be a string.',
            'location.max' => 'The room location cannot exceed 255 characters.',
        ];
    }
}
