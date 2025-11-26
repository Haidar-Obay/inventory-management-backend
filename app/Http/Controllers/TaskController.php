<?php

namespace App\Http\Controllers;

use App\Http\Requests\Task\StoreTaskRequest;
use App\Http\Requests\Task\UpdateTaskRequest;
use App\Models\Task;
use App\Models\User;
use App\Services\SchedulerService;
use Illuminate\Http\Request;

class TaskController extends Controller
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
        $key = "tenant_{$tenantId}_tasks";
        if ($startDate) {
            $key .= "_from_{$startDate}";
        }
        if ($endDate) {
            $key .= "_to_{$endDate}";
        }

        $tasks = app('cache')->store('database')->get($key);

        if (! $tasks) {
            $query = Task::with('schedulable');

            // Apply date range filtering if provided
            if ($startDate) {
                $query->whereDate('date', '>=', $startDate);
            }
            if ($endDate) {
                $query->whereDate('date', '<=', $endDate);
            }

            $tasks = $query->orderBy('date', 'desc')->orderBy('time', 'desc')->get();

            // Use shorter cache time for date-filtered queries
            $cacheTime = ($startDate || $endDate) ? 60 : null; // null = forever for full list
            if ($cacheTime) {
                app('cache')->store('database')->put($key, $tasks, $cacheTime);
            } else {
                app('cache')->store('database')->forever($key, $tasks);
            }
        }

        return response()->json([
            'status' => true,
            'message' => 'Tasks fetched successfully.',
            'data' => $tasks,
        ]);
    }

    public function store(StoreTaskRequest $request)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'status' => false,
                'message' => 'Authentication required to create tasks.',
            ], 401);
        }

        $validated = $request->validated();
        $validated['schedulable_type'] = User::class;
        $validated['schedulable_id'] = $user->id;

        $nextId = $this->computeNextAvailableId(Task::class, 'id');
        $task = new Task($validated);
        $task->id = $nextId;
        $task->save();

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_tasks");

        // Clear scheduler cache for this entity
        $this->schedulerService->clearCache($task->schedulable_type, $task->schedulable_id);

        return response()->json([
            'status' => true,
            'message' => 'Task created successfully.',
            'data' => $task->load('schedulable'),
        ], 201);
    }

    public function show(Task $task)
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_task_{$task->id}";

        $cachedTask = app('cache')->store('database')->get($key);

        if (! $cachedTask) {
            $cachedTask = $task->load('schedulable');

            app('cache')->store('database')->forever($key, $cachedTask);
        }

        return response()->json([
            'status' => true,
            'message' => 'Task details fetched successfully.',
            'data' => $cachedTask,
        ]);
    }

    public function update(UpdateTaskRequest $request, Task $task)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'status' => false,
                'message' => 'Authentication required to update tasks.',
            ], 401);
        }

        if ($task->schedulable_type !== User::class || $task->schedulable_id !== $user->id) {
            return response()->json([
                'status' => false,
                'message' => 'You are not allowed to modify this task.',
            ], 403);
        }

        $validated = $request->validated();
        $validated['schedulable_type'] = User::class;
        $validated['schedulable_id'] = $user->id;

        $task->update($validated);

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_tasks");
        app('cache')->store('database')->forget("tenant_{$tenantId}_task_{$task->id}");

        // Clear scheduler cache for this entity
        $this->schedulerService->clearCache($task->schedulable_type, $task->schedulable_id);

        return response()->json([
            'status' => true,
            'message' => 'Task updated successfully.',
            'data' => $task->load('schedulable'),
        ]);
    }

    public function destroy(Request $request, Task $task)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'status' => false,
                'message' => 'Authentication required to delete tasks.',
            ], 401);
        }

        if ($task->schedulable_type !== User::class || $task->schedulable_id !== $user->id) {
            return response()->json([
                'status' => false,
                'message' => 'You are not allowed to delete this task.',
            ], 403);
        }

        $schedulableType = $task->schedulable_type;
        $schedulableId = $task->schedulable_id;

        $task->delete();

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_tasks");
        app('cache')->store('database')->forget("tenant_{$tenantId}_task_{$task->id}");

        // Clear scheduler cache for this entity
        $this->schedulerService->clearCache($schedulableType, $schedulableId);

        return response()->json([
            'status' => true,
            'message' => 'Task deleted successfully.',
        ]);
    }

    public function forSchedulable(Request $request, $schedulableType, $schedulableId)
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_{$schedulableType}_{$schedulableId}_tasks";

        $tasks = app('cache')->store('database')->get($key);

        if (! $tasks) {
            $tasks = Task::forSchedulable($schedulableType, $schedulableId)
                ->with('schedulable')
                ->orderBy('date', 'desc')
                ->orderBy('time', 'desc')
                ->get();

            app('cache')->store('database')->forever($key, $tasks);
        }

        return response()->json([
            'status' => true,
            'message' => 'Tasks for schedulable fetched successfully.',
            'data' => $tasks,
        ]);
    }
}
