<?php

namespace App\Http\Controllers;

use App\Http\Requests\Event\StoreEventRequest;
use App\Http\Requests\Event\UpdateEventRequest;
use App\Models\Event;
use App\Models\User;
use App\Services\SchedulerService;
use Illuminate\Http\Request;

class EventController extends Controller
{
    protected SchedulerService $schedulerService;

    public function __construct(SchedulerService $schedulerService)
    {
        $this->schedulerService = $schedulerService;
    }

    public function index(Request $request)
    {
        $tenantId = tenant('id');

        // Get date range parameters
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');

        // Build cache key with date range if provided
        $key = "tenant_{$tenantId}_events";
        if ($startDate) {
            $key .= "_from_{$startDate}";
        }
        if ($endDate) {
            $key .= "_to_{$endDate}";
        }

        $events = app('cache')->store('database')->get($key);

        if (! $events) {
            $query = Event::with('schedulable');

            // Apply date range filtering if provided
            if ($startDate) {
                $query->whereDate('start_at', '>=', $startDate);
            }
            if ($endDate) {
                $query->whereDate('start_at', '<=', $endDate);
            }

            $events = $query->orderBy('start_at', 'desc')->get();

            // Use shorter cache time for date-filtered queries
            $cacheTime = ($startDate || $endDate) ? 60 : null; // null = forever for full list
            if ($cacheTime) {
                app('cache')->store('database')->put($key, $events, $cacheTime);
            } else {
                app('cache')->store('database')->forever($key, $events);
            }
        }

        return response()->json([
            'status' => true,
            'message' => 'Events fetched successfully.',
            'data' => $events,
        ]);
    }

    public function store(StoreEventRequest $request)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'status' => false,
                'message' => 'Authentication required to create events.',
            ], 401);
        }

        $validated = $request->validated();
        $validated['schedulable_type'] = User::class;
        $validated['schedulable_id'] = $user->id;

        // Remove status from validated data - it will be auto-calculated by the model
        // (unless it's 'cancelled', which can only be set via status toggle)
        unset($validated['status']);

        $nextId = $this->computeNextAvailableId(Event::class, 'id');
        $event = new Event($validated);
        $event->id = $nextId;
        // Status will be auto-calculated in the saving event
        $event->save();

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_events");

        // Clear scheduler cache for this entity
        $this->schedulerService->clearCache($event->schedulable_type, $event->schedulable_id);

        return response()->json([
            'status' => true,
            'message' => 'Event created successfully.',
            'data' => $event->load('schedulable'),
        ], 201);
    }

    public function show(Event $event)
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_event_{$event->id}";

        $cachedEvent = app('cache')->store('database')->get($key);

        if (! $cachedEvent) {
            $cachedEvent = $event->load('schedulable');

            app('cache')->store('database')->forever($key, $cachedEvent);
        }

        return response()->json([
            'status' => true,
            'message' => 'Event details fetched successfully.',
            'data' => $cachedEvent,
        ]);
    }

    public function update(UpdateEventRequest $request, Event $event)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'status' => false,
                'message' => 'Authentication required to update events.',
            ], 401);
        }

        if ($event->schedulable_type !== User::class || $event->schedulable_id !== $user->id) {
            return response()->json([
                'status' => false,
                'message' => 'You are not allowed to modify this event.',
            ], 403);
        }

        $validated = $request->validated();
        $validated['schedulable_type'] = User::class;
        $validated['schedulable_id'] = $user->id;

        // Remove status from validated data - it will be auto-calculated by the model
        // (unless it's 'cancelled', which can only be set via status toggle)
        unset($validated['status']);

        // Only update fields that were provided
        $event->fill($validated);
        // Status will be auto-calculated in the saving event
        $event->save();

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_events");
        app('cache')->store('database')->forget("tenant_{$tenantId}_event_{$event->id}");

        // Clear scheduler cache for this entity
        $this->schedulerService->clearCache($event->schedulable_type, $event->schedulable_id);

        return response()->json([
            'status' => true,
            'message' => 'Event updated successfully.',
            'data' => $event->load('schedulable'),
        ]);
    }

    public function destroy(Request $request, Event $event)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'status' => false,
                'message' => 'Authentication required to delete events.',
            ], 401);
        }

        if ($event->schedulable_type !== User::class || $event->schedulable_id !== $user->id) {
            return response()->json([
                'status' => false,
                'message' => 'You are not allowed to delete this event.',
            ], 403);
        }

        $schedulableType = $event->schedulable_type;
        $schedulableId = $event->schedulable_id;

        $event->delete();

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_events");
        app('cache')->store('database')->forget("tenant_{$tenantId}_event_{$event->id}");

        // Clear scheduler cache for this entity
        $this->schedulerService->clearCache($schedulableType, $schedulableId);

        return response()->json([
            'status' => true,
            'message' => 'Event deleted successfully.',
        ]);
    }

    public function forSchedulable(Request $request, $schedulableType, $schedulableId)
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_{$schedulableType}_{$schedulableId}_events";

        $events = app('cache')->store('database')->get($key);

        if (! $events) {
            $events = Event::forSchedulable($schedulableType, $schedulableId)
                ->with('schedulable')
                ->orderBy('start_at', 'desc')
                ->get();

            app('cache')->store('database')->forever($key, $events);
        }

        return response()->json([
            'status' => true,
            'message' => 'Events for schedulable fetched successfully.',
            'data' => $events,
        ]);
    }
}
