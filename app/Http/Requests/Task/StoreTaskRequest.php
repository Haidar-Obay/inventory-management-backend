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
            'schedulable_id' => 'required|integer',
            'schedulable_type' => 'required|string|in:App\Models\User,App\Models\Specialist,App\Models\Asset,App\Models\Room,App\Models\Section',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'start_at' => 'required|date',
            'end_at' => 'nullable|date|after:start_at',
            'due_at' => 'nullable|date',
            'status' => 'required|in:pending,in_progress,completed,cancelled',
            'priority' => 'required|in:low,medium,high,urgent',
            'notes' => 'nullable|string|max:1000',
            'color' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
        ];
    }

    public function messages(): array
    {
        return [
            'schedulable_id.required' => 'The schedulable ID is required.',
            'schedulable_type.required' => 'The schedulable type is required.',
            'schedulable_type.in' => 'The schedulable type must be one of: User, Specialist, Asset, Room, Section.',
            'title.required' => 'The title is required.',
            'title.max' => 'The title cannot exceed 255 characters.',
            'start_at.required' => 'The start date is required.',
            'start_at.date' => 'The start date must be a valid date.',
            'end_at.date' => 'The end date must be a valid date.',
            'end_at.after' => 'The end date must be after the start date.',
            'status.required' => 'The status is required.',
            'status.in' => 'The status must be one of: pending, in_progress, completed, cancelled.',
            'priority.required' => 'The priority is required.',
            'priority.in' => 'The priority must be one of: low, medium, high, urgent.',
            'notes.max' => 'The notes cannot exceed 1000 characters.',
            'color.regex' => 'The color must be a valid hex color code (e.g., #FF5733).',
        ];
    }
}
