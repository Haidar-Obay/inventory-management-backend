<?php

namespace App\Http\Requests\Assignment;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'asset_id' => 'sometimes|exists:assets,id',
            'user_id' => 'sometimes|exists:users,id',
            'start_at' => 'sometimes|date',
            'end_at' => 'nullable|date|after:start_at',
            'status' => 'sometimes|in:active,completed,cancelled,overdue',
            'notes' => 'nullable|string|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'asset_id.exists' => 'The selected asset does not exist.',
            'user_id.exists' => 'The selected user does not exist.',
            'start_at.date' => 'The start date must be a valid date.',
            'end_at.date' => 'The end date must be a valid date.',
            'end_at.after' => 'The end date must be after the start date.',
            'status.in' => 'The status must be one of: active, completed, cancelled, overdue.',
            'notes.string' => 'The notes must be a string.',
            'notes.max' => 'The notes cannot exceed 1000 characters.',
        ];
    }
}
