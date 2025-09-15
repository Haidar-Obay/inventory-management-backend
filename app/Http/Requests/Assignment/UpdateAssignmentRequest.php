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
            'user_id' => [
                'sometimes',
                'exists:users,id',
                function ($attribute, $value, $fail) {
                    if ($value) {
                        $user = \App\Models\User::with('roles')->find($value);
                        if ($user) {
                            $roleNames = $user->roles->pluck('name')->toArray();
                            $roleNamesLower = array_map('strtolower', $roleNames);
                            if (in_array('owner', $roleNamesLower, true) || in_array('admin', $roleNamesLower, true)) {
                                $fail('Cannot assign assets to users with owner or admin roles.');
                            }
                        }
                    }
                }
            ],
            'start_at' => 'sometimes|date',
            'end_at' => 'nullable|date|after:start_at',
            'status' => 'sometimes|in:active,completed,cancelled,overdue',
            'notes' => 'nullable|string|max:1000',
            'color' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
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
            'color.regex' => 'The color must be a valid hex color code (e.g., #FF5733).',
        ];
    }
}
