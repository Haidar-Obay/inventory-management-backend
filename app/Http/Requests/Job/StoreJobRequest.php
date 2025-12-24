<?php

namespace App\Http\Requests\Job;

use Illuminate\Foundation\Http\FormRequest;

class StoreJobRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'code' => 'required|string|max:255|unique:projects_jobs,code',
            'description' => 'required|string|max:255',
            'project_id' => 'required|exists:projects,id',
            'start_date' => 'required|date',
            'expected_date' => 'required|date|after_or_equal:start_date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ];
    }

    public function messages()
    {
        return [
            'code.required' => 'The job code is required.',
            'code.unique' => 'The job code has already been taken.',
            'description.required' => 'The job description is required.',
            'project_id.required' => 'The project is required.',
            'project_id.exists' => 'The selected project does not exist.',
            'start_date.required' => 'The start date is required.',
            'expected_date.required' => 'The expected date is required.',
            'expected_date.after_or_equal' => 'The expected date must be after or equal to the start date.',
            'end_date.required' => 'The end date is required.',
            'end_date.after_or_equal' => 'The end date must be after or equal to the start date.',
        ];
    }
}
