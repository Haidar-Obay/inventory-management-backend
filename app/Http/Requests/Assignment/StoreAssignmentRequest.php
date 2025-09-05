<?php

namespace App\Http\Requests\Assignment;

use Illuminate\Foundation\Http\FormRequest;

class StoreAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'asset_id' => 'required|exists:assets,id',
            'user_id' => 'required|exists:users,id',
            'start_at' => 'required|date',
            'end_at' => 'nullable|date|after:start_at',
            'status' => 'required|in:active,completed,cancelled,overdue',
            'notes' => 'nullable|string|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'asset_id.required' => 'The asset ID is required.',
            'asset_id.exists' => 'The selected asset does not exist.',
            'user_id.required' => 'The user ID is required.',
            'user_id.exists' => 'The selected user does not exist.',
            'start_at.required' => 'The start date is required.',
            'start_at.date' => 'The start date must be a valid date.',
            'end_at.date' => 'The end date must be a valid date.',
            'end_at.after' => 'The end date must be after the start date.',
            'status.required' => 'The status is required.',
            'status.in' => 'The status must be one of: active, completed, cancelled, overdue.',
            'notes.string' => 'The notes must be a string.',
            'notes.max' => 'The notes cannot exceed 1000 characters.',
        ];
    }
}
