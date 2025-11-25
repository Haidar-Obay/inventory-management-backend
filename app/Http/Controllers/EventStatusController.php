<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\User;
use App\Services\SchedulerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class EventStatusController extends Controller
{
    public function __construct(protected SchedulerService $schedulerService) {}

    public function toggleStatus(Request $request, Event $event)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'status' => false,
                'message' => 'Authentication required to update event status.',
            ], 401);
        }

        if ($event->schedulable_type !== User::class || $event->schedulable_id !== $user->id) {
            return response()->json([
                'status' => false,
                'message' => 'You are not allowed to modify this event.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'status' => 'required|in:scheduled,ongoing,completed,cancelled',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $newStatus = $request->input('status');
        $event->status = $newStatus;
        $event->save();

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_events");
        app('cache')->store('database')->forget("tenant_{$tenantId}_event_{$event->id}");

        // Clear scheduler cache for this entity
        $this->schedulerService->clearCache($event->schedulable_type, $event->schedulable_id);

        return response()->json([
            'status' => true,
            'message' => 'Event status updated successfully.',
            'data' => $event->load('schedulable'),
        ]);
    }
}
