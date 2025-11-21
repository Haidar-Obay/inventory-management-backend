<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Event;
use App\Models\Task;
use Carbon\Carbon;

class SchedulerService
{
    /**
     * Get all scheduler data for a given entity
     *
     * @param  string  $schedulableType  The model class (e.g., 'App\Models\User')
     * @param  int  $schedulableId  The entity ID
     * @param  array  $filters  Optional filters (date_from, date_to, status, type)
     * @return array Unified scheduler data
     */
    public function getSchedulerData(string $schedulableType, int $schedulableId, array $filters = []): array
    {
        $tenantId = tenant('id');
        $cacheKey = $this->getCacheKey($schedulableType, $schedulableId, $filters);

        $cached = app('cache')->store('database')->get($cacheKey);
        if ($cached) {
            return $cached;
        }

        $appointments = $this->getAppointments($schedulableType, $schedulableId, $filters);
        $tasks = $this->getTasks($schedulableType, $schedulableId, $filters);
        $events = $this->getEvents($schedulableType, $schedulableId, $filters);

        $data = [
            'schedulable_type' => $schedulableType,
            'schedulable_id' => $schedulableId,
            'appointments' => $appointments,
            'tasks' => $tasks,
            'events' => $events,
            'total' => count($appointments) + count($tasks) + count($events),
        ];

        // Cache for 5 minutes
        app('cache')->store('database')->put($cacheKey, $data, 300);

        return $data;
    }

    /**
     * Get appointments for the entity
     */
    protected function getAppointments(string $schedulableType, int $schedulableId, array $filters = []): array
    {
        $query = Appointment::query();

        // Appointments are not polymorphic, so we need to handle based on type
        if ($schedulableType === 'App\Models\Specialist') {
            $query->where('specialist_id', $schedulableId)
                ->with([
                    'asset:id,name,type,status,section_id',
                    'asset.section:id,name,room_id',
                    'asset.section.room:id,name,location',
                    'specialist:id,name',
                ]);
        } elseif ($schedulableType === 'App\Models\Asset') {
            $query->where('asset_id', $schedulableId)
                ->with([
                    'asset:id,name,type,status,section_id',
                    'asset.section:id,name,room_id',
                    'asset.section.room:id,name,location',
                    'specialist:id,name',
                ]);
        } else {
            // User, Room, Section don't have direct appointments
            return [];
        }

        // Apply filters
        $this->applyDateFilters($query, $filters);
        $this->applyStatusFilter($query, $filters, 'status');

        $appointments = $query->orderBy('start_at', 'asc')->get();

        return $appointments->map(function ($appointment) {
            return $this->formatAppointment($appointment);
        })->toArray();
    }

    /**
     * Get tasks for the entity
     */
    protected function getTasks(string $schedulableType, int $schedulableId, array $filters = []): array
    {
        $query = Task::forSchedulable($schedulableType, $schedulableId)
            ->with('schedulable');

        // Get date range from filters or use default (current month ± 1 month)
        $startDate = isset($filters['date_from']) 
            ? Carbon::parse($filters['date_from']) 
            : Carbon::now()->subMonth();
        $endDate = isset($filters['date_to']) 
            ? Carbon::parse($filters['date_to']) 
            : Carbon::now()->addMonths(2);

        // For recurring tasks, we need to include them even if master date is outside range
        // So we modify the date filter to include tasks with repeat rules
        if (isset($filters['date_from']) || isset($filters['date_to'])) {
            $query->where(function ($q) use ($filters, $startDate, $endDate) {
                // Include tasks within date range
                if (isset($filters['date_from'])) {
                    $q->where('date', '>=', $filters['date_from']);
                }
                if (isset($filters['date_to'])) {
                    $q->where('date', '<=', $filters['date_to']);
                }
                // Also include recurring tasks that might have instances in the range
                $q->orWhere(function ($subQ) use ($startDate, $endDate) {
                    $subQ->whereNotNull('repeat')
                        ->where('date', '<=', $endDate->format('Y-m-d'));
                    // Check if recurrence end_date extends into our range, or has no end
                    $subQ->where(function ($repeatQ) use ($startDate) {
                        $repeatQ->whereRaw("JSON_EXTRACT(repeat, '$.end_date') IS NULL")
                            ->orWhereRaw("JSON_EXTRACT(repeat, '$.end_date') >= ?", [$startDate->format('Y-m-d')]);
                    });
                });
            });
        }

        $this->applyStatusFilter($query, $filters, 'status');

        if (isset($filters['priority'])) {
            $query->where('priority', $filters['priority']);
        }

        $tasks = $query->orderBy('date', 'asc')->orderBy('time', 'asc')->get();

        $formattedTasks = $tasks->map(function ($task) {
            return $this->formatTask($task);
        })->toArray();

        // Expand recurring tasks into instances
        $expandedTasks = [];
        $recurrenceService = app(RecurrenceService::class);

        foreach ($formattedTasks as $task) {
            if (!empty($task['repeat']) && !empty($task['repeat']['frequency'])) {
                // Expand recurring task into instances
                $instances = $recurrenceService->generateInstances($task, $startDate, $endDate);
                $expandedTasks = array_merge($expandedTasks, $instances);
            } else {
                // Regular task, only add if within date range
                $taskDate = Carbon::parse($task['date']);
                if ($taskDate->gte($startDate) && $taskDate->lte($endDate)) {
                    $expandedTasks[] = $task;
                }
            }
        }

        return $expandedTasks;
    }

    /**
     * Get events for the entity
     */
    protected function getEvents(string $schedulableType, int $schedulableId, array $filters = []): array
    {
        $query = Event::forSchedulable($schedulableType, $schedulableId)
            ->with('schedulable');

        // Apply filters
        $this->applyDateFilters($query, $filters);
        $this->applyStatusFilter($query, $filters, 'status');

        $events = $query->orderBy('start_at', 'asc')->get();

        return $events->map(function ($event) {
            return $this->formatEvent($event);
        })->toArray();
    }

    /**
     * Format appointment for unified response
     */
    protected function formatAppointment(Appointment $appointment): array
    {
        $assetName = $appointment->asset?->name ?? 'No Asset';
        $specialistName = $appointment->specialist?->name ?? 'No Specialist';
        
        // Build title based on what's available
        if ($appointment->asset_id && $appointment->specialist_id) {
            $title = "{$assetName} - {$specialistName}";
        } elseif ($appointment->asset_id) {
            $title = $assetName;
        } elseif ($appointment->specialist_id) {
            $title = $specialistName;
        } else {
            $title = 'Appointment';
        }

        return [
            'id' => $appointment->id,
            'type' => 'appointment',
            'title' => $title,
            'description' => $appointment->notes,
            'start_at' => $appointment->start_at->toIso8601String(),
            'end_at' => $appointment->end_at?->toIso8601String(),
            'status' => $appointment->status,
            'color' => $appointment->color ?? '#10b981', // Use user's color or default, don't override with status
            'is_all_day' => false,
            'location' => null,
            'priority' => null,
            'due_at' => null,
            'metadata' => [
                'asset_id' => $appointment->asset_id,
                'asset_name' => $appointment->asset?->name ?? null,
                'specialist_id' => $appointment->specialist_id,
                'specialist_name' => $appointment->specialist?->name ?? null,
            ],
            'raw_data' => $appointment->toArray(),
        ];
    }

    /**
     * Format task for unified response
     */
    protected function formatTask(Task $task): array
    {
        // Combine date and time for start_at
        $startAt = null;
        if ($task->date) {
            if ($task->is_all_day || !$task->time) {
                // All-day task: use date at midnight
                $startAt = $task->date->copy()->setTime(0, 0, 0);
            } else {
                // Combine date and time
                $timeParts = explode(':', $task->time);
                $startAt = $task->date->copy()->setTime((int)$timeParts[0], (int)($timeParts[1] ?? 0), 0);
            }
        }

        // For end_at, if all-day, use end of day; otherwise use start_at + 1 hour
        $endAt = null;
        if ($startAt) {
            if ($task->is_all_day) {
                $endAt = $startAt->copy()->setTime(23, 59, 59);
            } else {
                $endAt = $startAt->copy()->addHour();
            }
        }

        return [
            'id' => $task->id,
            'type' => 'task',
            'title' => $task->title,
            'description' => $task->description,
            'start_at' => $startAt?->toIso8601String(),
            'end_at' => $endAt?->toIso8601String(),
            'status' => $task->status,
            'color' => $task->color ?? $this->getPriorityColor($task->priority),
            'is_all_day' => $task->is_all_day ?? false,
            'location' => null,
            'priority' => $task->priority,
            'due_at' => $task->due_at?->toIso8601String(),
            'repeat' => $task->repeat,
            'metadata' => [
                'schedulable_type' => $task->schedulable_type,
                'schedulable_id' => $task->schedulable_id,
            ],
            'raw_data' => $task->toArray(),
        ];
    }

    /**
     * Format event for unified response
     */
    protected function formatEvent(Event $event): array
    {
        return [
            'id' => $event->id,
            'type' => 'event',
            'title' => $event->title,
            'description' => $event->description,
            'start_at' => $event->start_at->toIso8601String(),
            'end_at' => $event->end_at?->toIso8601String(),
            'status' => $event->status,
            'color' => $event->color ?? '#3b82f6',
            'is_all_day' => $event->is_all_day ?? false,
            'location' => $event->location,
            'priority' => null,
            'due_at' => null,
            'metadata' => [
                'schedulable_type' => $event->schedulable_type,
                'schedulable_id' => $event->schedulable_id,
            ],
            'raw_data' => $event->toArray(),
        ];
    }

    /**
     * Apply date filters to query
     */
    protected function applyDateFilters($query, array $filters): void
    {
        if (isset($filters['date_from'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('start_at', '>=', $filters['date_from'])
                    ->orWhere('end_at', '>=', $filters['date_from']);
            });
        }

        if (isset($filters['date_to'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('start_at', '<=', $filters['date_to'])
                    ->orWhere('end_at', '<=', $filters['date_to']);
            });
        }
    }

    /**
     * Apply status filter to query
     */
    protected function applyStatusFilter($query, array $filters, string $statusColumn = 'status'): void
    {
        if (isset($filters['status'])) {
            if (is_array($filters['status'])) {
                $query->whereIn($statusColumn, $filters['status']);
            } else {
                $query->where($statusColumn, $filters['status']);
            }
        }
    }

    /**
     * Get status-based color
     */
    protected function getStatusColor(string $status): string
    {
        return match ($status) {
            'active' => '#10b981',        // Green - before start time
            'in_progress' => '#3b82f6',   // Blue - currently happening
            'completed' => '#6b7280',     // Gray - after end time
            default => '#10b981',
        };
    }

    /**
     * Get priority-based color
     */
    protected function getPriorityColor(string $priority): string
    {
        return match ($priority) {
            'urgent' => '#ef4444',
            'high' => '#f59e0b',
            'medium' => '#3b82f6',
            'low' => '#10b981',
            default => '#6b7280',
        };
    }

    /**
     * Get cache key
     */
    protected function getCacheKey(string $schedulableType, int $schedulableId, array $filters = []): string
    {
        $tenantId = tenant('id');
        $type = str_replace('App\\Models\\', '', $schedulableType);
        $filterHash = md5(json_encode($filters));

        return "tenant_{$tenantId}_scheduler_{$type}_{$schedulableId}_{$filterHash}";
    }

    /**
     * Clear cache for a specific entity
     */
    public function clearCache(string $schedulableType, int $schedulableId): void
    {
        $tenantId = tenant('id');
        $type = str_replace('App\\Models\\', '', $schedulableType);
        $pattern = "tenant_{$tenantId}_scheduler_{$type}_{$schedulableId}_*";

        // Note: This is a simple implementation. For production, consider using cache tags
        // or a more sophisticated cache invalidation strategy
        app('cache')->store('database')->forget("tenant_{$tenantId}_scheduler_{$type}_{$schedulableId}");
    }
}
