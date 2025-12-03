<?php

namespace App\Services;

use App\Models\Tenant;

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

        $schedulerMode = $tenant->getSchedulerMode();

        if ($schedulerMode === 'advanced') {
            return $this->restrictionService->validateRestrictions($data, $excludeAppointmentId);
        }

        // Basic mode: minimal validation
        return [
            'valid' => true,
            'errors' => [],
        ];
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
            'customer_ids' => 'nullable|array',
            'customer_ids.*' => 'exists:customers,id',
            'notes' => 'nullable|string|max:1000',
            'color' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
        ];

        if ($schedulerMode === 'advanced') {
            // Advanced: service, asset and specialist are all required
            $baseRules['service_id'] = 'required|exists:services,id';
            $baseRules['asset_id'] = 'required|exists:assets,id';
            $baseRules['specialist_id'] = 'required|exists:specialists,id';
        } else {
            // Basic: all optional
            $baseRules['service_id'] = 'nullable|exists:services,id';
            $baseRules['asset_id'] = 'nullable|exists:assets,id';
            $baseRules['specialist_id'] = 'nullable|exists:specialists,id';
        }

        return $baseRules;
    }
}
