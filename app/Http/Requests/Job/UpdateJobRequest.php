<?php

namespace App\Http\Requests\Job;

use Illuminate\Foundation\Http\FormRequest;

class UpdateJobRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'description' => 'required|string|max:255',
            'project_id' => 'required|exists:projects,id',
            'start_date' => 'required|date',
            'expected_date' => 'required|date|after_or_equal:start_date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ];
    }

    public function messages()
    {
        return [
            'description.required' => 'The job description is required.',
            'project_id.required' => 'The project is required.',
            'project_id.exists' => 'The selected project does not exist.',
            'start_date.required' => 'The start date is required.',
            'expected_date.required' => 'The expected date is required.',
            'expected_date.after_or_equal' => 'The expected date must be after or equal to the start date.',
            'end_date.after_or_equal' => 'The end date must be after or equal to the start date.',
        ];
    }
}
