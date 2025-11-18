<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Asset;
use App\Models\Specialist;
use Carbon\Carbon;

class AppointmentRestrictionService
{
    /**
     * Validate appointment restrictions for advanced scheduler
     *
     * @param array $data Appointment data
     * @param int|null $excludeAppointmentId Appointment ID to exclude (for updates)
     * @return array ['valid' => bool, 'errors' => array]
     */
    public function validateRestrictions(array $data, ?int $excludeAppointmentId = null): array
    {
        $errors = [];

        // Asset availability check
        if (isset($data['asset_id'])) {
            $assetError = $this->checkAssetAvailability($data['asset_id'], $data['start_at'], $data['end_at'] ?? null, $excludeAppointmentId);
            if ($assetError) {
                $errors[] = $assetError;
            }
        }

        // Specialist availability and capacity checks
        if (isset($data['specialist_id'])) {
            $specialistErrors = $this->checkSpecialistRestrictions(
                $data['specialist_id'],
                $data['start_at'],
                $data['end_at'] ?? null,
                $excludeAppointmentId
            );
            $errors = array_merge($errors, $specialistErrors);
        }

        // Both asset and specialist must be provided in advanced mode
        if (! isset($data['asset_id']) || ! isset($data['specialist_id'])) {
            $errors[] = 'Both asset and specialist are required in advanced scheduler mode.';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Check if asset is available during the time period
     */
    protected function checkAssetAvailability(?int $assetId, string $startAt, ?string $endAt, ?int $excludeId = null): ?string
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

        // Check for overlapping appointments
        $overlapping = Appointment::overlapping($assetId, $startAt, $endAt, $excludeId)
            ->where('status', '!=', 'cancelled')
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
        }

        // Check capacity per day
        if ($specialist->capacity_per_day) {
            $dayError = $this->checkDailyCapacity($specialist, $startDateTime, $excludeId);
            if ($dayError) {
                $errors[] = $dayError;
            }
        }

        // Check for overlapping appointments (basic availability)
        $overlapping = Appointment::where('specialist_id', $specialistId)
            ->where('status', '!=', 'cancelled')
            ->where('id', '!=', $excludeId)
            ->where(function ($query) use ($startDateTime, $endDateTime) {
                $query->whereBetween('start_at', [$startDateTime, $endDateTime])
                    ->orWhereBetween('end_at', [$startDateTime, $endDateTime])
                    ->orWhere(function ($q) use ($startDateTime, $endDateTime) {
                        $q->where('start_at', '<=', $startDateTime)
                            ->where('end_at', '>=', $endDateTime);
                    });
            })
            ->exists();

        if ($overlapping) {
            $errors[] = 'Specialist is already booked during the specified time period.';
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

            $count = Appointment::where('specialist_id', $specialist->id)
                ->where('status', '!=', 'cancelled')
                ->where('id', '!=', $excludeId)
                ->where(function ($query) use ($hourStart, $hourEnd) {
                    $query->whereBetween('start_at', [$hourStart, $hourEnd])
                        ->orWhereBetween('end_at', [$hourStart, $hourEnd])
                        ->orWhere(function ($q) use ($hourStart, $hourEnd) {
                            $q->where('start_at', '<=', $hourStart)
                                ->where('end_at', '>=', $hourEnd);
                        });
                })
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

        $count = Appointment::where('specialist_id', $specialist->id)
            ->where('status', '!=', 'cancelled')
            ->where('id', '!=', $excludeId)
            ->whereBetween('start_at', [$dayStart, $dayEnd])
            ->count();

        if ($count >= $capacity) {
            return "Specialist has reached daily capacity ({$capacity} appointment(s) per day) for {$date->format('Y-m-d')}.";
        }

        return null;
    }
}

