<?php

namespace App\Http\Requests\Room;

use Illuminate\Foundation\Http\FormRequest;

class StoreRoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255|unique:rooms,name',
            'location' => 'required|string|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'The room name is required.',
            'name.string' => 'The room name must be a string.',
            'name.max' => 'The room name cannot exceed 255 characters.',
            'name.unique' => 'The room name has already been taken.',
            'location.required' => 'The room location is required.',
            'location.string' => 'The room location must be a string.',
            'location.max' => 'The room location cannot exceed 255 characters.',
        ];
    }
}
