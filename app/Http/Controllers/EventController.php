<?php

namespace App\Http\Controllers;

use App\Http\Requests\Event\StoreEventRequest;
use App\Http\Requests\Event\UpdateEventRequest;
use App\Models\Event;
use App\Services\SchedulerService;
use Illuminate\Http\Request;

class EventController extends Controller
{
    protected SchedulerService $schedulerService;

    public function __construct(SchedulerService $schedulerService)
    {
        $this->schedulerService = $schedulerService;
    }

    public function index()
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_events";

        $events = app('cache')->store('database')->get($key);

        if (! $events) {
            $events = Event::with('schedulable')->orderBy('start_at', 'desc')->get();

            app('cache')->store('database')->forever($key, $events);
        }

        return response()->json([
            'status' => true,
            'message' => 'Events fetched successfully.',
            'data' => $events,
        ]);
    }

    public function store(StoreEventRequest $request)
    {
        $validated = $request->validated();

        $nextId = $this->computeNextAvailableId(Event::class, 'id');
        $event = new Event($validated);
        $event->id = $nextId;
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
        $validated = $request->validated();

        $event->update($validated);

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

    public function destroy(Event $event)
    {
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
