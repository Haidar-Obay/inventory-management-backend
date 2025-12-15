<?php

namespace App\Services;

use App\Models\Service;
use App\Models\Tenant;
use Carbon\Carbon;

class AppointmentValidationService
{
    protected AppointmentRestrictionService $restrictionService;

    public function __construct(AppointmentRestrictionService $restrictionService)
    {
        $this->restrictionService = $restrictionService;
    }

    /**
     * Validate appointment based on tenant's scheduler mode
     *
     * @param  array  $data  Appointment data
     * @param  int|null  $excludeAppointmentId  Appointment ID to exclude (for updates)
     * @return array ['valid' => bool, 'errors' => array]
     */
    public function validate(array $data, ?int $excludeAppointmentId = null): array
    {
        $tenant = tenant();

        if (! $tenant) {
            return [
                'valid' => false,
                'errors' => ['Tenant not found.'],
            ];
        }

        $errors = [];

        // Check service duration requirement (applies to both basic and advanced modes)
        // Support both service_id (legacy) and service_ids (multiple services)
        $startAt = $data['start_at'] ?? null;
        $endAt = $data['end_at'] ?? null;

        if ($startAt && $endAt) {
            // Handle services array (with service_id and optional specialist_id)
            $services = $data['services'] ?? null;

            // Handle service_ids array (simple array of service IDs)
            $serviceIds = $data['service_ids'] ?? null;

            // Handle legacy service_id for backward compatibility
            $serviceId = $data['service_id'] ?? null;
            if ($serviceId && ! $services && ! $serviceIds) {
                $serviceIds = [$serviceId];
            }

            // Validate duration for all services
            if (! empty($services) && is_array($services)) {
                // Format: [['service_id' => 1, 'specialist_id' => 5], ...]
                foreach ($services as $serviceData) {
                    $serviceId = is_array($serviceData) ? ($serviceData['service_id'] ?? null) : $serviceData;
                    if ($serviceId) {
                        $serviceDurationError = $this->checkServiceDuration($serviceId, $startAt, $endAt);
                        if ($serviceDurationError) {
                            $errors[] = $serviceDurationError;
                        }
                    }
                }
            } elseif (! empty($serviceIds) && is_array($serviceIds)) {
                // Simple array format
                foreach ($serviceIds as $serviceId) {
                    $serviceDurationError = $this->checkServiceDuration($serviceId, $startAt, $endAt);
                    if ($serviceDurationError) {
                        $errors[] = $serviceDurationError;
                    }
                }
            }
        }

        $schedulerMode = $tenant->getSchedulerMode();

        // Always validate restrictions (asset availability, specialist capacity, etc.)
        // This applies to both basic and advanced modes to prevent double-booking
        $restrictionResult = $this->restrictionService->validateRestrictions($data, $excludeAppointmentId, $schedulerMode);
        $errors = array_merge($errors, $restrictionResult['errors']);

        // In advanced mode, ensure at least one service is provided
        if ($schedulerMode === 'advanced') {
            $services = $data['services'] ?? null;
            $serviceIds = $data['service_ids'] ?? null;
            $serviceId = $data['service_id'] ?? null;

            if (empty($services) && empty($serviceIds) && empty($serviceId)) {
                $errors[] = 'At least one service is required in advanced scheduler mode.';
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Check if appointment duration meets service duration requirement
     */
    protected function checkServiceDuration($serviceId, $startAt, $endAt): ?string
    {
        // Validate service_id is not null/empty
        if (empty($serviceId)) {
            return null;
        }

        // Ensure service_id is an integer
        $serviceId = (int) $serviceId;
        if ($serviceId <= 0) {
            return null;
        }

        $service = Service::find($serviceId);
        if (! $service) {
            return 'Service not found.';
        }

        // If service has no duration_minutes set, skip validation
        if (empty($service->duration_minutes) || $service->duration_minutes <= 0) {
            return null;
        }

        // Parse dates - handle both string and Carbon instances
        try {
            $startDateTime = $startAt instanceof Carbon ? $startAt : Carbon::parse($startAt);
            $endDateTime = $endAt instanceof Carbon ? $endAt : Carbon::parse($endAt);
        } catch (\Exception $e) {
            return 'Invalid date format for start_at or end_at.';
        }

        $appointmentDurationMinutes = $startDateTime->diffInMinutes($endDateTime);

        if ($appointmentDurationMinutes < $service->duration_minutes) {
            return "Appointment duration ({$appointmentDurationMinutes} minutes) must be at least {$service->duration_minutes} minutes as required by the selected service.";
        }

        return null;
    }

    /**
     * Get validation rules based on scheduler mode
     */
    public function getValidationRules(): array
    {
        $tenant = tenant();
        $schedulerMode = $tenant->getSchedulerMode();

        $baseRules = [
            'start_at' => 'required|date',
            'end_at' => 'required|date|after:start_at',
            'notes' => 'nullable|string|max:1000',
            'color' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'status' => 'nullable|in:active,in_progress,completed,cancelled',
            'cancellation_reason' => 'nullable|string|max:1000',
            // These are managed by the backend when cancelling/undoing;
            // allow them but they are not required in requests.
            'cancelled_date' => 'nullable|date',
            'cancelled_time' => 'nullable|date_format:H:i:s',
        ];

        if ($schedulerMode === 'advanced') {
            // Advanced: customer, services, and specialists are required (assets are auto-assigned)
            $baseRules['customer_ids'] = 'required|array|min:1';
            $baseRules['customer_ids.*'] = 'exists:customers,id';
            // Advanced: at least one service (services, service_ids, or service_id), specialist required
            // Note: asset_id is optional - backend will automatically assign an available asset
            $baseRules['services'] = 'nullable|array|min:1';
            $baseRules['services.*.service_id'] = 'required_with:services|exists:services,id';
            $baseRules['services.*.specialist_id'] = 'required_with:services|exists:specialists,id';
            $baseRules['services.*.asset_id'] = 'nullable|exists:assets,id'; // Optional - auto-assigned if not provided
            $baseRules['service_ids'] = 'nullable|array|min:1';
            $baseRules['service_ids.*'] = 'exists:services,id';
            $baseRules['service_id'] = 'nullable|exists:services,id'; // Legacy support
        } else {
            // Basic: all optional
            $baseRules['customer_ids'] = 'nullable|array';
            $baseRules['customer_ids.*'] = 'exists:customers,id';
            $baseRules['services'] = 'nullable|array';
            $baseRules['services.*.service_id'] = 'required_with:services|exists:services,id';
            $baseRules['services.*.specialist_id'] = 'nullable|exists:specialists,id';
            $baseRules['services.*.asset_id'] = 'nullable|exists:assets,id';
            $baseRules['service_ids'] = 'nullable|array';
            $baseRules['service_ids.*'] = 'exists:services,id';
            $baseRules['service_id'] = 'nullable|exists:services,id'; // Legacy support
        }

        return $baseRules;
    }
}
