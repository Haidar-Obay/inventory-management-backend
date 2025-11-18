<?php

namespace App\Http\Controllers;

use App\Http\Requests\Task\StoreTaskRequest;
use App\Http\Requests\Task\UpdateTaskRequest;
use App\Models\Task;
use App\Services\SchedulerService;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    protected SchedulerService $schedulerService;

    public function __construct(SchedulerService $schedulerService)
    {
        $this->schedulerService = $schedulerService;
    }

    public function index()
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_tasks";

        $tasks = app('cache')->store('database')->get($key);

        if (! $tasks) {
            $tasks = Task::with('schedulable')->orderBy('start_at', 'desc')->get();

            app('cache')->store('database')->forever($key, $tasks);
        }

        return response()->json([
            'status' => true,
            'message' => 'Tasks fetched successfully.',
            'data' => $tasks,
        ]);
    }

    public function store(StoreTaskRequest $request)
    {
        $validated = $request->validated();

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
        $validated = $request->validated();

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

    public function destroy(Task $task)
    {
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
                ->orderBy('start_at', 'desc')
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
