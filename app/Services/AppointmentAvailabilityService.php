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
            $daySlots = $this->findSlotsForDay($currentDate, $possibleAssets, $possibleSpecialists, $durationMinutes);

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
     * Find available time slots for a specific day
     */
    protected function findSlotsForDay(Carbon $date, array $possibleAssets, array $possibleSpecialists, int $durationMinutes): array
    {
        $slots = [];
        $dayStart = $date->copy()->setTime(8, 0); // Start at 8 AM
        $dayEnd = $date->copy()->setTime(20, 0); // End at 8 PM
        $slotInterval = 30; // Check every 30 minutes

        // Get current time to filter out past slots
        $now = Carbon::now();

        $currentTime = $dayStart->copy();

        while ($currentTime->copy()->addMinutes($durationMinutes)->lte($dayEnd)) {
            $slotStart = $currentTime->copy();
            $slotEnd = $slotStart->copy()->addMinutes($durationMinutes);

            // Skip slots that are in the past (only show future slots)
            // Skip if slot start time is in the past or equal to now (can't book in the past)
            if ($slotStart->lte($now)) {
                $currentTime->addMinutes($slotInterval);

                continue;
            }

            // Also skip if slot has already ended (double check)
            if ($slotEnd->lte($now)) {
                $currentTime->addMinutes($slotInterval);

                continue;
            }

            // Check if this slot is available (must have at least one available asset)
            $slotInfo = $this->checkSlotAvailability($slotStart, $slotEnd, $possibleAssets, $possibleSpecialists);

            // Only include slot if there are available assets
            if ($slotInfo['available'] && ! empty($slotInfo['assets'])) {
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
     * Check if a time slot is available with available assets and specialists
     */
    protected function checkSlotAvailability(Carbon $start, Carbon $end, array $possibleAssets, array $possibleSpecialists): array
    {
        $availableAssets = [];
        $availableSpecialists = [];
        $seenAssetIds = [];
        $seenSpecialistIds = [];

        // Check each asset for availability
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
