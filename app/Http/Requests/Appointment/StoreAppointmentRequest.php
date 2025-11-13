<?php

namespace App\Http\Requests\Appointment;

use Illuminate\Foundation\Http\FormRequest;

class StoreAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'asset_id' => 'required|exists:assets,id',
            'specialist_id' => 'required|exists:specialists,id',
            'start_at' => 'required|date',
            'end_at' => 'nullable|date|after:start_at',
            'status' => 'required|in:active,completed,cancelled,overdue',
            'notes' => 'nullable|string|max:1000',
            'color' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
        ];
    }

    public function messages(): array
    {
        return [
            'asset_id.required' => 'The asset ID is required.',
            'asset_id.exists' => 'The selected asset does not exist.',
            'specialist_id.required' => 'The specialist ID is required.',
            'specialist_id.exists' => 'The selected specialist does not exist.',
            'start_at.required' => 'The start date is required.',
            'start_at.date' => 'The start date must be a valid date.',
            'end_at.date' => 'The end date must be a valid date.',
            'end_at.after' => 'The end date must be after the start date.',
            'status.required' => 'The status is required.',
            'status.in' => 'The status must be one of: active, completed, cancelled, overdue.',
            'notes.string' => 'The notes must be a string.',
            'notes.max' => 'The notes cannot exceed 1000 characters.',
            'color.regex' => 'The color must be a valid hex color code (e.g., #FF5733).',
        ];
    }
}

