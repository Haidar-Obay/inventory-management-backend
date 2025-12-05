<?php

namespace App\Http\Requests\Appointment;

use App\Services\AppointmentValidationService;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $validationService = app(AppointmentValidationService::class);
        $baseRules = $validationService->getValidationRules();

        // For updates, make fields 'sometimes' instead of 'required'
        // Handle 'required_with' separately to avoid creating invalid 'sometimes_with'
        $updateRules = [];
        foreach ($baseRules as $key => $rule) {
            if (is_string($rule)) {
                // First, handle 'required_with' by replacing it with a placeholder
                $tempRule = str_replace('required_with', 'PLACEHOLDER_REQUIRED_WITH', $rule);
                // Then replace standalone 'required' with 'sometimes'
                $tempRule = preg_replace('/\brequired\b/', 'sometimes', $tempRule);
                // Finally, replace placeholder back to 'required_with' and add 'sometimes|' prefix
                $updateRules[$key] = str_replace('PLACEHOLDER_REQUIRED_WITH', 'sometimes|required_with', $tempRule);
            } else {
                $updateRules[$key] = $rule;
            }
        }

        return $updateRules;
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $validationService = app(AppointmentValidationService::class);
            $appointment = $this->route('appointment');
            
            // Merge existing appointment data with new data
            $existingData = $appointment->toArray();
            $newData = $this->all();
            
            // Ensure dates are in string format for validation
            if (isset($existingData['start_at']) && $existingData['start_at'] instanceof \Carbon\Carbon) {
                $existingData['start_at'] = $existingData['start_at']->toDateTimeString();
            }
            if (isset($existingData['end_at']) && $existingData['end_at'] instanceof \Carbon\Carbon) {
                $existingData['end_at'] = $existingData['end_at']->toDateTimeString();
            }
            
            $data = array_merge($existingData, $newData);
            
            // Normalize service_id - convert string "null" or empty string to null
            if (isset($data['service_id'])) {
                if ($data['service_id'] === 'null' || $data['service_id'] === '' || $data['service_id'] === null) {
                    $data['service_id'] = null;
                } else {
                    $data['service_id'] = (int) $data['service_id'];
                }
            } elseif (isset($existingData['service_id'])) {
                // Preserve existing service_id if not provided in update
                $data['service_id'] = $existingData['service_id'];
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
                // Keep empty array to allow clearing services, don't convert to null
                $data['services'] = $normalizedServices;
            }
            
            // Normalize service_ids array - ensure all values are integers
            if (isset($data['service_ids']) && is_array($data['service_ids'])) {
                $data['service_ids'] = array_filter(array_map(function ($id) {
                    if ($id === 'null' || $id === '' || $id === null) {
                        return null;
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
            } elseif (! isset($newData['services']) && ! isset($newData['service_ids']) && ! isset($newData['service_id'])) {
                // If services not provided in update, preserve existing services (only if services field is not sent at all)
                // If services is sent as empty array, it means user wants to clear services
                $existingServices = $appointment->services()->get();
                if ($existingServices->isNotEmpty()) {
                    $data['services'] = $existingServices->map(function ($service) {
                        return [
                            'service_id' => $service->id,
                            'specialist_id' => $service->pivot->specialist_id ?? null,
                            'asset_id' => $service->pivot->asset_id ?? null,
                        ];
                    })->toArray();
                }
            }
            
            $validation = $validationService->validate($data, $appointment->id);

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
            'asset_id.exists' => 'The selected asset does not exist.',
            'specialist_id.exists' => 'The selected specialist does not exist.',
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
