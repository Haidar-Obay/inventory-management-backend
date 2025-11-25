<?php

namespace App\Http\Requests\Task;

use Illuminate\Foundation\Http\FormRequest;

class StoreTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'date' => 'required|date',
            'time' => 'nullable|date_format:H:i',
            'is_all_day' => 'boolean',
            'repeat' => 'nullable|array',
            'due_at' => 'nullable|date',
            'status' => 'required|in:completed,uncompleted',
            'priority' => 'required|in:low,medium,high,urgent',
            'color' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'The title is required.',
            'title.max' => 'The title cannot exceed 255 characters.',
            'date.required' => 'The date is required.',
            'date.date' => 'The date must be a valid date.',
            'time.date_format' => 'The time must be in HH:mm format.',
            'due_at.date' => 'The due date must be a valid date.',
            'status.required' => 'The status is required.',
            'status.in' => 'The status must be either completed or uncompleted.',
            'priority.required' => 'The priority is required.',
            'priority.in' => 'The priority must be one of: low, medium, high, urgent.',
            'color.regex' => 'The color must be a valid hex color code (e.g., #FF5733).',
        ];
    }
}
