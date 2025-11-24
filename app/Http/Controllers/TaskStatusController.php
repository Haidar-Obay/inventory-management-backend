<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Models\User;
use App\Services\SchedulerService;
use Illuminate\Http\Request;

class TaskStatusController extends Controller
{
    public function __construct(protected SchedulerService $schedulerService) {}

    public function update(Request $request, Task $task)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'status' => false,
                'message' => 'Authentication required to update task status.',
            ], 401);
        }

        if ($task->schedulable_type !== User::class || $task->schedulable_id !== $user->id) {
            return response()->json([
                'status' => false,
                'message' => 'You are not allowed to modify this task.',
            ], 403);
        }

        $validated = $request->validate([
            'status' => 'required|in:completed,uncompleted',
        ]);

        $task->status = $validated['status'];
        $task->save();

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_tasks");
        app('cache')->store('database')->forget("tenant_{$tenantId}_task_{$task->id}");

        $this->schedulerService->clearCache($task->schedulable_type, $task->schedulable_id);

        return response()->json([
            'status' => true,
            'message' => 'Task status updated successfully.',
            'data' => $task->fresh('schedulable'),
        ]);
    }
}
