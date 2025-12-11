<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Asset;
use App\Models\Service;
use App\Models\Specialist;
use Carbon\Carbon;

class AppointmentRestrictionService
{
    /**
     * Validate appointment restrictions (asset availability, specialist capacity, etc.)
     * Applies to both basic and advanced scheduler modes
     *
     * @param  array  $data  Appointment data
     * @param  int|null  $excludeAppointmentId  Appointment ID to exclude (for updates)
     * @param  string  $schedulerMode  Scheduler mode ('basic' or 'advanced')
     * @return array ['valid' => bool, 'errors' => array]
     */
    public function validateRestrictions(array $data, ?int $excludeAppointmentId = null, string $schedulerMode = 'basic'): array
    {
        $errors = [];

        // Asset availability check
        if (isset($data['asset_id'])) {
            $assetError = $this->checkAssetAvailability($data['asset_id'], $data['start_at'], $data['end_at'] ?? null, $excludeAppointmentId);
            if ($assetError) {
                $errors[] = $assetError;
            }
        }

        // Specialist availability and capacity checks for per-service specialists
        $services = $data['services'] ?? null;
        $serviceIds = $data['service_ids'] ?? null;

        // Check if we have services with specialists
        $hasSpecialists = false;
        if (! empty($services) && is_array($services)) {
            foreach ($services as $serviceData) {
                $specialistId = is_array($serviceData) ? ($serviceData['specialist_id'] ?? null) : null;
                if ($specialistId) {
                    $hasSpecialists = true;
                    $specialistErrors = $this->checkSpecialistRestrictions(
                        $specialistId,
                        $data['start_at'],
                        $data['end_at'] ?? null,
                        $excludeAppointmentId
                    );
                    $errors = array_merge($errors, $specialistErrors);
                }
            }
        }

        // Handle legacy specialist_id for backward compatibility during transition
        if (isset($data['specialist_id']) && $data['specialist_id']) {
            $hasSpecialists = true;
            $specialistErrors = $this->checkSpecialistRestrictions(
                $data['specialist_id'],
                $data['start_at'],
                $data['end_at'] ?? null,
                $excludeAppointmentId
            );
            $errors = array_merge($errors, $specialistErrors);
        }

        // Asset availability and capacity checks for per-service assets
        $hasAssets = false;
        if (! empty($services) && is_array($services)) {
            foreach ($services as $serviceData) {
                $assetId = is_array($serviceData) ? ($serviceData['asset_id'] ?? null) : null;
                if ($assetId) {
                    $hasAssets = true;
                    $assetError = $this->checkAssetAvailability(
                        $assetId,
                        $data['start_at'],
                        $data['end_at'] ?? null,
                        $excludeAppointmentId
                    );
                    if ($assetError) {
                        $errors[] = $assetError;
                    }
                }
            }
        }

        // Handle legacy asset_id for backward compatibility during transition
        if (isset($data['asset_id']) && $data['asset_id']) {
            $hasAssets = true;
            $assetError = $this->checkAssetAvailability(
                $data['asset_id'],
                $data['start_at'],
                $data['end_at'] ?? null,
                $excludeAppointmentId
            );
            if ($assetError) {
                $errors[] = $assetError;
            }
        }

        // At least one specialist must be provided in advanced mode (assets are auto-assigned)
        if ($schedulerMode === 'advanced' && ! $hasSpecialists) {
            $errors[] = 'At least one specialist (per service) is required in advanced scheduler mode.';
        }

        // Check service hour capacity for all services
        if (! empty($services) && is_array($services)) {
            foreach ($services as $serviceData) {
                $serviceId = is_array($serviceData) ? ($serviceData['service_id'] ?? null) : null;
                if ($serviceId) {
                    $serviceCapacityError = $this->checkServiceHourCapacity(
                        $serviceId,
                        $data['start_at'],
                        $data['end_at'] ?? null,
                        $excludeAppointmentId
                    );
                    if ($serviceCapacityError) {
                        $errors[] = $serviceCapacityError;
                    }
                }
            }
        }

        // Handle service_ids array format
        if (! empty($serviceIds) && is_array($serviceIds)) {
            foreach ($serviceIds as $serviceId) {
                if ($serviceId) {
                    $serviceCapacityError = $this->checkServiceHourCapacity(
                        $serviceId,
                        $data['start_at'],
                        $data['end_at'] ?? null,
                        $excludeAppointmentId
                    );
                    if ($serviceCapacityError) {
                        $errors[] = $serviceCapacityError;
                    }
                }
            }
        }

        // Handle legacy service_id format
        if (isset($data['service_id']) && $data['service_id']) {
            $serviceCapacityError = $this->checkServiceHourCapacity(
                $data['service_id'],
                $data['start_at'],
                $data['end_at'] ?? null,
                $excludeAppointmentId
            );
            if ($serviceCapacityError) {
                $errors[] = $serviceCapacityError;
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Check if asset is available during the time period
     */
    public function checkAssetAvailability(?int $assetId, string $startAt, ?string $endAt, ?int $excludeId = null): ?string
    {
        if (! $assetId) {
            return null;
        }

        $asset = Asset::find($assetId);
        if (! $asset) {
            return 'Asset not found.';
        }

        // Check if asset is active
        if ($asset->status !== 'active') {
            return 'Asset is not available (status: '.$asset->status.').';
        }

        // Check for overlapping appointments - query through pivot table
        // Two time ranges overlap if: start1 < end2 && start2 < end1
        $startDateTime = Carbon::parse($startAt);
        $endDateTime = $endAt ? Carbon::parse($endAt) : $startDateTime->copy()->addHour();

        $overlapping = Appointment::whereHas('services', function ($q) use ($assetId) {
            $q->where('appointment_service.asset_id', $assetId);
        })
            ->where('status', '!=', 'cancelled')
            ->where('id', '!=', $excludeId)
            ->where('start_at', '<', $endDateTime)
            ->where('end_at', '>', $startDateTime)
            ->exists();

        if ($overlapping) {
            return 'This asset is already booked during the specified time period.';
        }

        return null;
    }

    /**
     * Check specialist restrictions (availability, capacity per hour, capacity per day)
     */
    protected function checkSpecialistRestrictions(?int $specialistId, string $startAt, ?string $endAt, ?int $excludeId = null): array
    {
        $errors = [];

        if (! $specialistId) {
            return $errors;
        }

        $specialist = Specialist::find($specialistId);
        if (! $specialist) {
            return ['Specialist not found.'];
        }

        $startDateTime = Carbon::parse($startAt);
        $endDateTime = $endAt ? Carbon::parse($endAt) : $startDateTime->copy()->addHour();

        // Check capacity per hour
        if ($specialist->capacity_per_hour) {
            $hourError = $this->checkHourlyCapacity($specialist, $startDateTime, $endDateTime, $excludeId);
            if ($hourError) {
                $errors[] = $hourError;
            }
            // If capacity_per_hour is set, we allow overlaps up to the capacity limit
            // So we skip the basic overlap check below
        } else {
            // If capacity_per_hour is NOT set (or is null), default to capacity of 1
            // This means no overlaps allowed - check for basic availability
            $overlapping = Appointment::whereHas('services', function ($q) use ($specialistId) {
                $q->where('appointment_service.specialist_id', $specialistId);
            })
                ->where('status', '!=', 'cancelled')
                ->where('id', '!=', $excludeId)
                ->where('start_at', '<', $endDateTime)
                ->where('end_at', '>', $startDateTime)
                ->exists();

            if ($overlapping) {
                $errors[] = 'Specialist is already booked during the specified time period.';
            }
        }

        // Check capacity per day
        if ($specialist->capacity_per_day) {
            $dayError = $this->checkDailyCapacity($specialist, $startDateTime, $excludeId);
            if ($dayError) {
                $errors[] = $dayError;
            }
        }

        return $errors;
    }

    /**
     * Check if specialist has capacity for this hour
     */
    protected function checkHourlyCapacity(Specialist $specialist, Carbon $startAt, Carbon $endAt, ?int $excludeId = null): ?string
    {
        $capacity = $specialist->capacity_per_hour ?? 1;

        // Get all hours covered by this appointment
        $hours = [];
        $current = $startAt->copy()->startOfHour();

        while ($current->lte($endAt)) {
            $hours[] = $current->copy();
            $current->addHour();
        }

        foreach ($hours as $hour) {
            $hourStart = $hour->copy();
            $hourEnd = $hour->copy()->endOfHour();

            // Count appointments that overlap with this hour
            // Two time ranges overlap if: start1 < end2 && start2 < end1
            $count = Appointment::whereHas('services', function ($q) use ($specialist) {
                $q->where('appointment_service.specialist_id', $specialist->id);
            })
                ->where('status', '!=', 'cancelled')
                ->where('id', '!=', $excludeId)
                ->where('start_at', '<', $hourEnd)
                ->where('end_at', '>', $hourStart)
                ->count();

            if ($count >= $capacity) {
                return "Specialist has reached hourly capacity ({$capacity} appointment(s) per hour) for {$hour->format('Y-m-d H:00')}.";
            }
        }

        return null;
    }

    /**
     * Check if specialist has capacity for this day
     */
    protected function checkDailyCapacity(Specialist $specialist, Carbon $date, ?int $excludeId = null): ?string
    {
        $capacity = $specialist->capacity_per_day;

        if (! $capacity) {
            return null; // Unlimited
        }

        $dayStart = $date->copy()->startOfDay();
        $dayEnd = $date->copy()->endOfDay();

        $count = Appointment::whereHas('services', function ($q) use ($specialist) {
            $q->where('appointment_service.specialist_id', $specialist->id);
        })
            ->where('status', '!=', 'cancelled')
            ->where('id', '!=', $excludeId)
            ->whereBetween('start_at', [$dayStart, $dayEnd])
            ->count();

        if ($count >= $capacity) {
            return "Specialist has reached daily capacity ({$capacity} appointment(s) per day) for {$date->format('Y-m-d')}.";
        }

        return null;
    }

    /**
     * Check if service has hour capacity available
     * If service has hour_capacity set, only that many appointments can overlap at the same time
     */
    public function checkServiceHourCapacity(?int $serviceId, string $startAt, ?string $endAt, ?int $excludeId = null): ?string
    {
        if (! $serviceId) {
            return null;
        }

        $service = Service::find($serviceId);
        if (! $service) {
            return 'Service not found.';
        }

        // If service has no hour_capacity set, no restriction (unlimited)
        if (! $service->hour_capacity || $service->hour_capacity <= 0) {
            return null;
        }

        $capacity = $service->hour_capacity;
        $startDateTime = Carbon::parse($startAt);
        $endDateTime = $endAt ? Carbon::parse($endAt) : $startDateTime->copy()->addHour();

        // Get all hours covered by this appointment
        $hours = [];
        $current = $startDateTime->copy()->startOfHour();

        while ($current->lte($endDateTime)) {
            $hours[] = $current->copy();
            $current->addHour();
        }

        foreach ($hours as $hour) {
            $hourStart = $hour->copy();
            $hourEnd = $hour->copy()->endOfHour();

            // Count appointments that overlap with this hour AND overlap with the new appointment
            // We need to check if adding the new appointment would exceed capacity
            // So we count existing appointments that overlap with both this hour AND the new appointment time
            $count = Appointment::whereHas('services', function ($q) use ($serviceId) {
                $q->where('appointment_service.service_id', $serviceId);
            })
                ->where('status', '!=', 'cancelled')
                ->where('id', '!=', $excludeId)
                // Overlap with the hour
                ->where('start_at', '<', $hourEnd)
                ->where('end_at', '>', $hourStart)
                // Also overlap with the new appointment (to ensure we're counting concurrent appointments)
                ->where('start_at', '<', $endDateTime)
                ->where('end_at', '>', $startDateTime)
                ->count();

            // Check if the new appointment overlaps with this hour
            $newAppointmentOverlapsHour = $startDateTime < $hourEnd && $endDateTime > $hourStart;
            if ($newAppointmentOverlapsHour) {
                // Add 1 for the new appointment we're trying to create
                $count += 1;
            }

            if ($count > $capacity) {
                return "Service has reached hourly capacity ({$capacity} appointment(s) per hour) for {$hour->format('Y-m-d H:00')}.";
            }
        }

        return null;
    }
}
