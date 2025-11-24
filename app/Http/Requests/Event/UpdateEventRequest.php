<?php

namespace App\Http\Requests\Event;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string|max:2000',
            'start_at' => 'sometimes|date',
            'end_at' => 'nullable|date|after:start_at',
            'status' => 'nullable|in:scheduled,ongoing,completed,cancelled', // Optional - will be auto-calculated unless 'cancelled'
            'location' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:1000',
            'color' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'is_all_day' => 'boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'title.max' => 'The title cannot exceed 255 characters.',
            'start_at.date' => 'The start date must be a valid date.',
            'end_at.date' => 'The end date must be a valid date.',
            'end_at.after' => 'The end date must be after the start date.',
            'status.in' => 'The status must be one of: scheduled, ongoing, completed, cancelled.',
            'location.max' => 'The location cannot exceed 255 characters.',
            'notes.max' => 'The notes cannot exceed 1000 characters.',
            'color.regex' => 'The color must be a valid hex color code (e.g., #FF5733).',
        ];
    }
}
