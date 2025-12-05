<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Asset;
use App\Models\Service;
use App\Models\Specialist;
use Carbon\Carbon;

class AppointmentAvailabilityService
{
    protected AppointmentRestrictionService $restrictionService;

    public function __construct(AppointmentRestrictionService $restrictionService)
    {
        $this->restrictionService = $restrictionService;
    }

    /**
     * Find available appointment slots for given services
     *
     * @param array $services Array of service selections: [['service_id' => int, 'specialist_id' => int|null, 'asset_id' => int|null], ...]
     * @param int $daysAhead Number of days to look ahead (default: 30)
     * @param string|null $startDate Start date (Y-m-d), defaults to today
     * @return array ['available_slots' => [['date' => string, 'time_slots' => [...]]]]
     */
    public function findAvailableSlots(array $services, int $daysAhead = 30, ?string $startDate = null): array
    {
        if (empty($services)) {
            return ['available_slots' => []];
        }

        $start = $startDate ? Carbon::parse($startDate)->startOfDay() : Carbon::today()->startOfDay();
        $end = $start->copy()->addDays($daysAhead)->endOfDay();

        // Calculate total duration needed
        $totalDurationMinutes = $this->calculateTotalDuration($services);
        
        // Get all possible asset/specialist combinations for the services
        $serviceConfigs = $this->buildServiceConfigs($services);

        // Find available slots
        $availableSlots = [];
        $currentDate = $start->copy();

        while ($currentDate->lte($end)) {
            $daySlots = $this->findSlotsForDay($currentDate, $serviceConfigs, $totalDurationMinutes);
            
            if (!empty($daySlots)) {
                $availableSlots[] = [
                    'date' => $currentDate->format('Y-m-d'),
                    'date_display' => $currentDate->format('l, F j, Y'),
                    'time_slots' => $daySlots,
                ];
            }

            $currentDate->addDay();
        }

        return ['available_slots' => $availableSlots];
    }

    /**
     * Calculate total duration in minutes for all services
     */
    protected function calculateTotalDuration(array $services): int
    {
        $total = 0;
        
        foreach ($services as $serviceData) {
            $serviceId = is_array($serviceData) ? ($serviceData['service_id'] ?? null) : null;
            if (!$serviceId) continue;

            $service = Service::find($serviceId);
            if ($service && $service->duration_minutes) {
                $total += $service->duration_minutes;
            }
        }

        // Default to 60 minutes if no duration specified
        return $total > 0 ? $total : 60;
    }

    /**
     * Build service configurations with all possible asset/specialist combinations
     */
    protected function buildServiceConfigs(array $services): array
    {
        $configs = [];

        foreach ($services as $serviceData) {
            $serviceId = is_array($serviceData) ? ($serviceData['service_id'] ?? null) : null;
            if (!$serviceId) continue;

            $service = Service::find($serviceId);
            if (!$service) continue;

            $specialistId = is_array($serviceData) ? ($serviceData['specialist_id'] ?? null) : null;
            $assetId = is_array($serviceData) ? ($serviceData['asset_id'] ?? null) : null;

            // If specialist/asset specified, use only those
            // Otherwise, get all available specialists/assets for this service
            $possibleSpecialists = $specialistId 
                ? [Specialist::find($specialistId)] 
                : $service->specialists()->get()->all();
            
            $possibleAssets = $assetId 
                ? [Asset::find($assetId)] 
                : $service->assets()->where('status', 'active')->get()->all();

            // Filter out nulls
            $possibleSpecialists = array_filter($possibleSpecialists);
            $possibleAssets = array_filter($possibleAssets);

            $configs[] = [
                'service_id' => $serviceId,
                'service' => $service,
                'specialist_id' => $specialistId,
                'asset_id' => $assetId,
                'possible_specialists' => $possibleSpecialists,
                'possible_assets' => $possibleAssets,
            ];
        }

        return $configs;
    }

    /**
     * Find available time slots for a specific day
     */
    protected function findSlotsForDay(Carbon $date, array $serviceConfigs, int $totalDurationMinutes): array
    {
        $slots = [];
        $dayStart = $date->copy()->setTime(8, 0); // Start at 8 AM
        $dayEnd = $date->copy()->setTime(20, 0); // End at 8 PM
        $slotInterval = 30; // Check every 30 minutes

        $currentTime = $dayStart->copy();

        while ($currentTime->copy()->addMinutes($totalDurationMinutes)->lte($dayEnd)) {
            $slotStart = $currentTime->copy();
            $slotEnd = $slotStart->copy()->addMinutes($totalDurationMinutes);

            // Check if this slot is available for all services
            $slotInfo = $this->checkSlotAvailability($slotStart, $slotEnd, $serviceConfigs);
            
            if ($slotInfo['available']) {
                $slots[] = [
                    'start' => $slotStart->format('H:i'),
                    'end' => $slotEnd->format('H:i'),
                    'start_datetime' => $slotStart->toDateTimeString(),
                    'end_datetime' => $slotEnd->toDateTimeString(),
                    'assets' => $slotInfo['assets'],
                    'specialists' => $slotInfo['specialists'],
                ];
            }

            $currentTime->addMinutes($slotInterval);
        }

        return $slots;
    }

    /**
     * Check if a time slot is available for all services
     */
    protected function checkSlotAvailability(Carbon $start, Carbon $end, array $serviceConfigs): array
    {
        $availableAssets = [];
        $availableSpecialists = [];

        foreach ($serviceConfigs as $config) {
            $serviceAvailable = false;
            $serviceAssets = [];
            $serviceSpecialists = [];

            // Try to find a valid combination of asset and specialist for this service
            foreach ($config['possible_assets'] as $asset) {
                if ($asset->status !== 'active') continue;

                // Check asset availability using reflection or public wrapper
                $assetError = $this->checkAssetAvailability(
                    $asset->id,
                    $start->toDateTimeString(),
                    $end->toDateTimeString()
                );

                if ($assetError) continue; // Asset not available

                // Try to find an available specialist for this asset
                foreach ($config['possible_specialists'] as $specialist) {
                    if (!$specialist) continue;

                    // Check specialist availability
                    $specialistErrors = $this->checkSpecialistRestrictions(
                        $specialist->id,
                        $start->toDateTimeString(),
                        $end->toDateTimeString()
                    );

                    if (!empty($specialistErrors)) continue; // Specialist not available

                    // Found a valid combination
                    $serviceAvailable = true;
                    $serviceAssets[] = [
                        'id' => $asset->id,
                        'name' => $asset->name,
                    ];
                    $serviceSpecialists[] = [
                        'id' => $specialist->id,
                        'name' => $specialist->name,
                    ];
                    break; // Found one, move to next service
                }

                if ($serviceAvailable) break; // Found valid combination for this service
            }

            if (!$serviceAvailable) {
                // This service has no available slot
                return ['available' => false, 'assets' => [], 'specialists' => []];
            }

            // Collect assets and specialists (we'll use the first available combination)
            if (!empty($serviceAssets)) {
                $availableAssets = array_merge($availableAssets, $serviceAssets);
            }
            if (!empty($serviceSpecialists)) {
                $availableSpecialists = array_merge($availableSpecialists, $serviceSpecialists);
            }
        }

        // Remove duplicates
        $availableAssets = array_values(array_unique(array_column($availableAssets, 'id')));
        $availableSpecialists = array_values(array_unique(array_column($availableSpecialists, 'id')));

        return [
            'available' => true,
            'assets' => $availableAssets,
            'specialists' => $availableSpecialists,
        ];
    }

    /**
     * Check if asset is available (wrapper for AppointmentRestrictionService)
     */
    protected function checkAssetAvailability(?int $assetId, string $startAt, ?string $endAt): ?string
    {
        if (!$assetId) {
            return null;
        }

        $asset = Asset::find($assetId);
        if (!$asset) {
            return 'Asset not found.';
        }

        if ($asset->status !== 'active') {
            return 'Asset is not available (status: '.$asset->status.').';
        }

        $startDateTime = Carbon::parse($startAt);
        $endDateTime = $endAt ? Carbon::parse($endAt) : $startDateTime->copy()->addHour();
        
        $overlapping = Appointment::whereHas('services', function ($q) use ($assetId) {
                $q->where('appointment_service.asset_id', $assetId);
            })
            ->where('status', '!=', 'cancelled')
            ->where('start_at', '<', $endDateTime)
            ->where('end_at', '>', $startDateTime)
            ->exists();

        if ($overlapping) {
            return 'This asset is already booked during the specified time period.';
        }

        return null;
    }

    /**
     * Check specialist restrictions (wrapper for AppointmentRestrictionService)
     */
    protected function checkSpecialistRestrictions(?int $specialistId, string $startAt, ?string $endAt): array
    {
        $errors = [];

        if (!$specialistId) {
            return $errors;
        }

        $specialist = Specialist::find($specialistId);
        if (!$specialist) {
            return ['Specialist not found.'];
        }

        $startDateTime = Carbon::parse($startAt);
        $endDateTime = $endAt ? Carbon::parse($endAt) : $startDateTime->copy()->addHour();

        // Check capacity per hour
        if ($specialist->capacity_per_hour) {
            $hourError = $this->checkHourlyCapacity($specialist, $startDateTime, $endDateTime);
            if ($hourError) {
                $errors[] = $hourError;
            }
        } else {
            // Default to capacity of 1 - no overlaps allowed
            $overlapping = Appointment::whereHas('services', function ($q) use ($specialistId) {
                    $q->where('appointment_service.specialist_id', $specialistId);
                })
                ->where('status', '!=', 'cancelled')
                ->where('start_at', '<', $endDateTime)
                ->where('end_at', '>', $startDateTime)
                ->exists();

            if ($overlapping) {
                $errors[] = 'Specialist is already booked during the specified time period.';
            }
        }

        // Check capacity per day
        if ($specialist->capacity_per_day) {
            $dayError = $this->checkDailyCapacity($specialist, $startDateTime);
            if ($dayError) {
                $errors[] = $dayError;
            }
        }

        return $errors;
    }

    /**
     * Check if specialist has capacity for this hour
     */
    protected function checkHourlyCapacity(Specialist $specialist, Carbon $startAt, Carbon $endAt): ?string
    {
        $capacity = $specialist->capacity_per_hour ?? 1;

        $hours = [];
        $current = $startAt->copy()->startOfHour();

        while ($current->lte($endAt)) {
            $hours[] = $current->copy();
            $current->addHour();
        }

        foreach ($hours as $hour) {
            $hourStart = $hour->copy();
            $hourEnd = $hour->copy()->endOfHour();

            $count = Appointment::whereHas('services', function ($q) use ($specialist) {
                    $q->where('appointment_service.specialist_id', $specialist->id);
                })
                ->where('status', '!=', 'cancelled')
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
    protected function checkDailyCapacity(Specialist $specialist, Carbon $date): ?string
    {
        $capacity = $specialist->capacity_per_day;

        if (!$capacity) {
            return null;
        }

        $dayStart = $date->copy()->startOfDay();
        $dayEnd = $date->copy()->endOfDay();

        $count = Appointment::whereHas('services', function ($q) use ($specialist) {
                $q->where('appointment_service.specialist_id', $specialist->id);
            })
            ->where('status', '!=', 'cancelled')
            ->whereBetween('start_at', [$dayStart, $dayEnd])
            ->count();

        if ($count >= $capacity) {
            return "Specialist has reached daily capacity ({$capacity} appointment(s) per day) for {$date->format('Y-m-d')}.";
        }

        return null;
    }
}

