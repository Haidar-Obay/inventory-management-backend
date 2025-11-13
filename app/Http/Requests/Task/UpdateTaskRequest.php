<?php

namespace App\Http\Requests\Task;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'schedulable_id' => 'sometimes|integer',
            'schedulable_type' => 'sometimes|string|in:App\Models\User,App\Models\Specialist,App\Models\Asset,App\Models\Room,App\Models\Section',
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string|max:2000',
            'start_at' => 'sometimes|date',
            'end_at' => 'nullable|date|after:start_at',
            'due_at' => 'nullable|date',
            'status' => 'sometimes|in:pending,in_progress,completed,cancelled',
            'priority' => 'sometimes|in:low,medium,high,urgent',
            'notes' => 'nullable|string|max:1000',
            'color' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
        ];
    }

    public function messages(): array
    {
        return [
            'schedulable_type.in' => 'The schedulable type must be one of: User, Specialist, Asset, Room, Section.',
            'title.max' => 'The title cannot exceed 255 characters.',
            'start_at.date' => 'The start date must be a valid date.',
            'end_at.date' => 'The end date must be a valid date.',
            'end_at.after' => 'The end date must be after the start date.',
            'status.in' => 'The status must be one of: pending, in_progress, completed, cancelled.',
            'priority.in' => 'The priority must be one of: low, medium, high, urgent.',
            'notes.max' => 'The notes cannot exceed 1000 characters.',
            'color.regex' => 'The color must be a valid hex color code (e.g., #FF5733).',
        ];
    }
}
