<?php

namespace App\Http\Requests\Appointment;

use App\Services\AppointmentValidationService;
use Illuminate\Foundation\Http\FormRequest;

class StoreAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $validationService = app(AppointmentValidationService::class);
        return $validationService->getValidationRules();
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $validationService = app(AppointmentValidationService::class);
            $data = $this->all();
            $validation = $validationService->validate($data);

            if (! $validation['valid']) {
                foreach ($validation['errors'] as $error) {
                    $validator->errors()->add('appointment', $error);
                }
            }
        });
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
            'end_at.required' => 'The end date is required.',
            'end_at.date' => 'The end date must be a valid date.',
            'end_at.after' => 'The end date must be after the start date.',
            'notes.string' => 'The notes must be a string.',
            'notes.max' => 'The notes cannot exceed 1000 characters.',
            'color.regex' => 'The color must be a valid hex color code (e.g., #FF5733).',
        ];
    }
}
