<?php

namespace App\Services;

use Carbon\Carbon;

class RecurrenceService
{
    /**
     * Generate recurring task instances from a master task
     *
     * @param  array  $masterTask  The master task with repeat rules
     * @param  Carbon  $startDate  Start date for generating instances
     * @param  Carbon  $endDate  End date for generating instances
     * @return array Array of task instances
     */
    public function generateInstances(array $masterTask, Carbon $startDate, Carbon $endDate): array
    {
        if (empty($masterTask['repeat']) || empty($masterTask['repeat']['frequency'])) {
            // Not a recurring task, return as single instance
            return [$masterTask];
        }

        $repeat = $masterTask['repeat'];
        $frequency = $repeat['frequency'];
        $interval = $repeat['interval'] ?? 1;
        $endDateLimit = isset($repeat['end_date']) ? Carbon::parse($repeat['end_date']) : null;
        $count = $repeat['count'] ?? null; // Optional: limit number of occurrences

        // Handle date field - could be string or Carbon
        $masterDate = is_string($masterTask['date'])
            ? Carbon::parse($masterTask['date'])
            : ($masterTask['date'] instanceof Carbon ? $masterTask['date'] : Carbon::parse($masterTask['date']));

        $instances = [];
        $currentDate = $masterDate->copy();
        $occurrenceCount = 0;

        // Generate instances within the requested date range
        while ($currentDate->lte($endDate)) {
            // Check if we've exceeded the end date limit
            if ($endDateLimit && $currentDate->gt($endDateLimit)) {
                break;
            }

            // Check if we've exceeded the count limit
            if ($count !== null && $occurrenceCount >= $count) {
                break;
            }

            // Only include dates within the requested range
            if ($currentDate->gte($startDate)) {
                $instance = $this->createInstance($masterTask, $currentDate, $occurrenceCount);
                $instances[] = $instance;
            }

            // Move to next occurrence based on frequency
            $currentDate = $this->getNextOccurrence($currentDate, $frequency, $interval);
            $occurrenceCount++;
        }

        return $instances;
    }

    /**
     * Create a task instance from master task
     */
    protected function createInstance(array $masterTask, Carbon $date, int $occurrenceIndex): array
    {
        $instance = $masterTask;

        // Update date for this instance
        $instance['date'] = $date->format('Y-m-d');

        // Create a unique ID for this instance (master_id + occurrence_index)
        $instance['id'] = $masterTask['id'].'_'.$occurrenceIndex;
        $instance['master_id'] = $masterTask['id'];
        $instance['occurrence_index'] = $occurrenceIndex;
        $instance['is_recurring_instance'] = true;
        $instance['is_recurring'] = true; // Flag for frontend

        // Update start_at and end_at if they exist
        if (isset($instance['start_at'])) {
            $startAt = Carbon::parse($instance['start_at']);
            $startAt->setDate($date->year, $date->month, $date->day);
            $instance['start_at'] = $startAt->toIso8601String();
        }

        if (isset($instance['end_at'])) {
            $endAt = Carbon::parse($instance['end_at']);
            $endAt->setDate($date->year, $date->month, $date->day);
            $instance['end_at'] = $endAt->toIso8601String();
        }

        // Update due_at if it exists (can be relative to occurrence date)
        if (isset($instance['due_at'])) {
            $dueAt = Carbon::parse($instance['due_at']);
            // Calculate relative due date based on original offset
            $originalDate = Carbon::parse($masterTask['date']);
            $daysOffset = $originalDate->diffInDays($dueAt);
            $newDueAt = $date->copy()->addDays($daysOffset);
            $instance['due_at'] = $newDueAt->toIso8601String();
        }

        return $instance;
    }

    /**
     * Get the next occurrence date based on frequency
     */
    protected function getNextOccurrence(Carbon $currentDate, string $frequency, int $interval): Carbon
    {
        return match ($frequency) {
            'daily' => $currentDate->copy()->addDays($interval),
            'weekly' => $currentDate->copy()->addWeeks($interval),
            'monthly' => $currentDate->copy()->addMonths($interval),
            'yearly' => $currentDate->copy()->addYears($interval),
            default => $currentDate->copy()->addDay(), // Default to daily
        };
    }

    /**
     * Get human-readable recurrence description
     */
    public function getRecurrenceDescription(array $repeat): string
    {
        if (empty($repeat) || empty($repeat['frequency'])) {
            return 'No repeat';
        }

        $frequency = $repeat['frequency'];
        $interval = $repeat['interval'] ?? 1;
        $endDate = isset($repeat['end_date']) ? Carbon::parse($repeat['end_date'])->format('M j, Y') : null;
        $count = $repeat['count'] ?? null;

        $intervalText = $interval > 1 ? "every {$interval} " : 'every ';

        $frequencyText = match ($frequency) {
            'daily' => $intervalText.'day'.($interval > 1 ? 's' : ''),
            'weekly' => $intervalText.'week'.($interval > 1 ? 's' : ''),
            'monthly' => $intervalText.'month'.($interval > 1 ? 's' : ''),
            'yearly' => $intervalText.'year'.($interval > 1 ? 's' : ''),
            default => 'Unknown',
        };

        if ($endDate) {
            return "Repeats {$frequencyText} until {$endDate}";
        } elseif ($count) {
            return "Repeats {$frequencyText} ({$count} times)";
        } else {
            return "Repeats {$frequencyText}";
        }
    }
}
