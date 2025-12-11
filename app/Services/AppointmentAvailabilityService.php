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
     * Find available appointment slots for a single service
     *
     * @param  int  $serviceId  Service ID
     * @param  int|null  $specialistId  Optional specialist ID
     * @param  int  $daysAhead  Number of days to look ahead (default: 10)
     * @param  string|null  $startDate  Start date (Y-m-d), defaults to today
     * @return array ['available_slots' => [['date' => string, 'time_slots' => [...]]]]
     */
    public function findAvailableSlots(int $serviceId, ?int $specialistId = null, int $daysAhead = 10, ?string $startDate = null): array
    {
        $service = Service::find($serviceId);
        if (! $service) {
            return ['available_slots' => []];
        }

        $start = $startDate ? Carbon::parse($startDate)->startOfDay() : Carbon::today()->startOfDay();
        $end = $start->copy()->addDays($daysAhead)->endOfDay();

        // Get service duration (default to 60 minutes if not specified)
        $durationMinutes = $service->duration_minutes ?? 60;

        // Get all assets for this service (automatically check all, no user selection)
        $possibleAssets = $service->assets()->where('status', 'active')->get()->all();

        // Get specialists (either the specified one or all for this service)
        $possibleSpecialists = $specialistId
            ? [Specialist::find($specialistId)]
            : $service->specialists()->get()->all();

        // Filter out nulls
        $possibleSpecialists = array_filter($possibleSpecialists);

        // Find available slots
        $availableSlots = [];
        $currentDate = $start->copy();

        while ($currentDate->lte($end)) {
            $daySlots = $this->findSlotsForDay($currentDate, $possibleAssets, $possibleSpecialists, $durationMinutes, $service);

            if (! empty($daySlots)) {
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
     * Find available time slots for a specific day using gap-based approach
     * Finds gaps between existing appointments and only suggests slots that fit
     */
    protected function findSlotsForDay(Carbon $date, array $possibleAssets, array $possibleSpecialists, int $durationMinutes, Service $service): array
    {
        $slots = [];
        $dayStart = $date->copy()->setTime(8, 0); // Start at 8 AM
        $dayEnd = $date->copy()->setTime(20, 0); // End at 8 PM

        // Get current time to filter out past slots
        $now = Carbon::now();

        // Get all existing appointments for this service on this day
        $existingAppointments = Appointment::whereHas('services', function ($q) use ($service) {
            $q->where('appointment_service.service_id', $service->id);
        })
            ->where('status', '!=', 'cancelled')
            ->whereDate('start_at', $date->format('Y-m-d'))
            ->orderBy('start_at', 'asc')
            ->get(['id', 'start_at', 'end_at']);

        // Build list of occupied time ranges
        $occupiedRanges = [];
        foreach ($existingAppointments as $appointment) {
            $occupiedRanges[] = [
                'start' => Carbon::parse($appointment->start_at),
                'end' => Carbon::parse($appointment->end_at),
            ];
        }

        // Find gaps between occupied ranges
        $gaps = [];
        $currentTime = max($dayStart, $now->copy()->startOfMinute()); // Start from now or day start, whichever is later

        foreach ($occupiedRanges as $occupied) {
            // If there's a gap before this occupied range
            if ($currentTime < $occupied['start']) {
                $gapEnd = min($occupied['start'], $dayEnd);
                if ($gapEnd > $currentTime) {
                    $gaps[] = [
                        'start' => $currentTime->copy(),
                        'end' => $gapEnd->copy(),
                    ];
                }
            }
            // Move current time to after this occupied range
            $currentTime = max($currentTime, $occupied['end']);
        }

        // Add gap from last appointment to end of day
        if ($currentTime < $dayEnd) {
            $gaps[] = [
                'start' => $currentTime->copy(),
                'end' => $dayEnd->copy(),
            ];
        }

        // If no appointments, the whole day is a gap
        if (empty($occupiedRanges) && $currentTime < $dayEnd) {
            $gaps[] = [
                'start' => $currentTime->copy(),
                'end' => $dayEnd->copy(),
            ];
        }

        // For each gap, find slots that fit the service duration
        // Suggest slots starting right at the beginning of gaps (after existing appointments)
        foreach ($gaps as $gap) {
            $gapStart = $gap['start'];
            $gapEnd = $gap['end'];

            // Start from the beginning of the gap
            $slotStart = $gapStart->copy();

            // Keep finding consecutive slots that fit in this gap
            while ($slotStart->copy()->addMinutes($durationMinutes)->lte($gapEnd)) {
                $slotEnd = $slotStart->copy()->addMinutes($durationMinutes);

                // Skip if slot is in the past
                if ($slotEnd->lte($now)) {
                    $slotStart = $slotEnd->copy(); // Move to after this slot

                    continue;
                }

                // Check if this slot is available (assets, specialists, capacity)
                $slotInfo = $this->checkSlotAvailability($slotStart, $slotEnd, $possibleAssets, $possibleSpecialists);

                // Include slot if:
                // 1. Service has no assets (no asset checking required)
                // 2. Service has assets and at least one is available
                $hasNoAssets = empty($possibleAssets);
                $hasAvailableAssets = $slotInfo['available'] && ! empty($slotInfo['assets']);

                // Check service hour capacity if set
                $hasCapacity = true;
                if ($service->hour_capacity && $service->hour_capacity > 0) {
                    $capacityError = $this->restrictionService->checkServiceHourCapacity(
                        $service->id,
                        $slotStart->toDateTimeString(),
                        $slotEnd->toDateTimeString()
                    );
                    // If there's an error, capacity is reached
                    $hasCapacity = $capacityError === null;
                }

                // Only add slot if it passes all checks
                if (($hasNoAssets || $hasAvailableAssets) && $hasCapacity) {
                    $slots[] = [
                        'start' => $slotStart->format('H:i'),
                        'end' => $slotEnd->format('H:i'),
                        'start_datetime' => $slotStart->toDateTimeString(),
                        'end_datetime' => $slotEnd->toDateTimeString(),
                        'assets' => $slotInfo['assets'],
                        'specialists' => $slotInfo['specialists'],
                    ];

                    // Move to right after this slot ends for next consecutive slot
                    $slotStart = $slotEnd->copy();
                } else {
                    // If slot is not available, skip it and try starting from after it
                    $slotStart = $slotEnd->copy();
                }
            }
        }

        return $slots;
    }

    /**
     * Check if a time slot is available with available assets and specialists
     */
    protected function checkSlotAvailability(Carbon $start, Carbon $end, array $possibleAssets, array $possibleSpecialists): array
    {
        $availableAssets = [];
        $availableSpecialists = [];
        $seenAssetIds = [];
        $seenSpecialistIds = [];

        // If service has no assets, no asset checking is required
        if (empty($possibleAssets)) {
            // Still check specialists if any are specified
            if (! empty($possibleSpecialists)) {
                foreach ($possibleSpecialists as $specialist) {
                    if (! $specialist) {
                        continue;
                    }

                    $specialistErrors = $this->checkSpecialistRestrictions(
                        $specialist->id,
                        $start->toDateTimeString(),
                        $end->toDateTimeString()
                    );

                    if (empty($specialistErrors)) {
                        if (! in_array($specialist->id, $seenSpecialistIds)) {
                            $availableSpecialists[] = [
                                'id' => $specialist->id,
                                'name' => $specialist->name,
                            ];
                            $seenSpecialistIds[] = $specialist->id;
                        }
                    }
                }
            }

            // Return available=true since no assets are required
            return [
                'available' => true,
                'assets' => [],
                'specialists' => $availableSpecialists,
            ];
        }

        // Service has assets - check each asset for availability
        foreach ($possibleAssets as $asset) {
            if ($asset->status !== 'active') {
                continue;
            }

            // Check asset availability
            $assetError = $this->checkAssetAvailability(
                $asset->id,
                $start->toDateTimeString(),
                $end->toDateTimeString()
            );

            if ($assetError) {
                continue;
            } // Asset not available

            // If no specialist specified, asset is available
            // If specialist specified, check if that specialist is available for this asset
            if (empty($possibleSpecialists)) {
                // No specialist required, asset is available
                if (! in_array($asset->id, $seenAssetIds)) {
                    $availableAssets[] = [
                        'id' => $asset->id,
                        'name' => $asset->name,
                    ];
                    $seenAssetIds[] = $asset->id;
                }
            } else {
                // Check if any specialist is available for this asset
                foreach ($possibleSpecialists as $specialist) {
                    if (! $specialist) {
                        continue;
                    }

                    // Check specialist availability
                    $specialistErrors = $this->checkSpecialistRestrictions(
                        $specialist->id,
                        $start->toDateTimeString(),
                        $end->toDateTimeString()
                    );

                    if (! empty($specialistErrors)) {
                        continue;
                    } // Specialist not available

                    // Found a valid combination
                    if (! in_array($asset->id, $seenAssetIds)) {
                        $availableAssets[] = [
                            'id' => $asset->id,
                            'name' => $asset->name,
                        ];
                        $seenAssetIds[] = $asset->id;
                    }
                    if (! in_array($specialist->id, $seenSpecialistIds)) {
                        $availableSpecialists[] = [
                            'id' => $specialist->id,
                            'name' => $specialist->name,
                        ];
                        $seenSpecialistIds[] = $specialist->id;
                    }

                    break; // Found one specialist for this asset
                }
            }
        }

        return [
            'available' => ! empty($availableAssets),
            'assets' => $availableAssets,
            'specialists' => $availableSpecialists,
        ];
    }

    /**
     * Check if asset is available (wrapper for AppointmentRestrictionService)
     */
    protected function checkAssetAvailability(?int $assetId, string $startAt, ?string $endAt): ?string
    {
        if (! $assetId) {
            return null;
        }

        $asset = Asset::find($assetId);
        if (! $asset) {
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

        if (! $capacity) {
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
