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

            // Normalize service_id - convert string "null" or empty string to null
            if (isset($data['service_id'])) {
                if ($data['service_id'] === 'null' || $data['service_id'] === '' || $data['service_id'] === null) {
                    $data['service_id'] = null;
                } else {
                    $data['service_id'] = (int) $data['service_id'];
                }
            }

            // Normalize services array - format: [['service_id' => 1, 'specialist_id' => 5, 'asset_id' => 3], ...]
            if (isset($data['services']) && is_array($data['services'])) {
                $normalizedServices = [];
                foreach ($data['services'] as $serviceData) {
                    if (is_array($serviceData)) {
                        $serviceId = $serviceData['service_id'] ?? null;
                        $specialistId = $serviceData['specialist_id'] ?? null;
                        $assetId = $serviceData['asset_id'] ?? null;

                        if ($serviceId && ($serviceId === 'null' || $serviceId === '' || $serviceId === null)) {
                            continue; // Skip invalid entries
                        }

                        if ($serviceId) {
                            $normalizedServices[] = [
                                'service_id' => (int) $serviceId,
                                'specialist_id' => ($specialistId && $specialistId !== 'null' && $specialistId !== '') ? (int) $specialistId : null,
                                'asset_id' => ($assetId && $assetId !== 'null' && $assetId !== '') ? (int) $assetId : null,
                            ];
                        }
                    } elseif ($serviceData && $serviceData !== 'null' && $serviceData !== '') {
                        // Simple format: just service ID
                        $normalizedServices[] = [
                            'service_id' => (int) $serviceData,
                            'specialist_id' => null,
                            'asset_id' => null,
                        ];
                    }
                }
                $data['services'] = ! empty($normalizedServices) ? $normalizedServices : null;
            }

            // Normalize service_ids array - ensure all values are integers
            if (isset($data['service_ids']) && is_array($data['service_ids'])) {
                $data['service_ids'] = array_filter(array_map(function ($id) {
                    if ($id === 'null' || $id === '' || $id === null) {
                        return;
                    }

                    return (int) $id;
                }, $data['service_ids']), function ($id) {
                    return $id !== null && $id > 0;
                });
                // Re-index array
                $data['service_ids'] = array_values($data['service_ids']);
                // If empty after filtering, set to null
                if (empty($data['service_ids'])) {
                    $data['service_ids'] = null;
                }
            }

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
