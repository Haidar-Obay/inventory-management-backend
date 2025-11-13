<?php

namespace App\Http\Controllers;

use App\Services\SchedulerService;
use Illuminate\Http\Request;

class SchedulerUnitController extends Controller
{
    protected SchedulerService $schedulerService;

    public function __construct(SchedulerService $schedulerService)
    {
        $this->schedulerService = $schedulerService;
    }

    /**
     * Get scheduler data for a specific entity
     *
     * @param  string  $schedulableType  The model class (e.g., 'User', 'Specialist', 'Asset')
     * @param  int  $schedulableId  The entity ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(Request $request, string $schedulableType, int $schedulableId)
    {
        // Validate schedulable type
        $allowedTypes = [
            'User' => 'App\Models\User',
            'Specialist' => 'App\Models\Specialist',
            'Asset' => 'App\Models\Asset',
            'Room' => 'App\Models\Room',
            'Section' => 'App\Models\Section',
        ];

        if (! isset($allowedTypes[$schedulableType])) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid schedulable type. Allowed types: '.implode(', ', array_keys($allowedTypes)),
            ], 422);
        }

        $fullType = $allowedTypes[$schedulableType];

        // Build filters from request
        $filters = [];
        if ($request->has('date_from')) {
            $filters['date_from'] = $request->input('date_from');
        }
        if ($request->has('date_to')) {
            $filters['date_to'] = $request->input('date_to');
        }
        if ($request->has('status')) {
            $filters['status'] = $request->input('status');
        }
        if ($request->has('type')) {
            $filters['type'] = $request->input('type'); // appointment, task, event
        }
        if ($request->has('priority')) {
            $filters['priority'] = $request->input('priority');
        }

        try {
            $data = $this->schedulerService->getSchedulerData($fullType, $schedulableId, $filters);

            return response()->json([
                'status' => true,
                'message' => 'Scheduler data fetched successfully.',
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to fetch scheduler data.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get unified timeline (all items combined and sorted)
     */
    public function timeline(Request $request, string $schedulableType, int $schedulableId)
    {
        // Validate schedulable type
        $allowedTypes = [
            'User' => 'App\Models\User',
            'Specialist' => 'App\Models\Specialist',
            'Asset' => 'App\Models\Asset',
            'Room' => 'App\Models\Room',
            'Section' => 'App\Models\Section',
        ];

        if (! isset($allowedTypes[$schedulableType])) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid schedulable type.',
            ], 422);
        }

        $fullType = $allowedTypes[$schedulableType];

        // Build filters
        $filters = [];
        if ($request->has('date_from')) {
            $filters['date_from'] = $request->input('date_from');
        }
        if ($request->has('date_to')) {
            $filters['date_to'] = $request->input('date_to');
        }
        if ($request->has('status')) {
            $filters['status'] = $request->input('status');
        }
        if ($request->has('type')) {
            $filters['type'] = $request->input('type');
        }

        try {
            $data = $this->schedulerService->getSchedulerData($fullType, $schedulableId, $filters);

            // Combine all items into a single timeline
            $timeline = array_merge(
                $data['appointments'],
                $data['tasks'],
                $data['events']
            );

            // Sort by start_at
            usort($timeline, function ($a, $b) {
                return strtotime($a['start_at']) <=> strtotime($b['start_at']);
            });

            return response()->json([
                'status' => true,
                'message' => 'Timeline fetched successfully.',
                'data' => [
                    'schedulable_type' => $data['schedulable_type'],
                    'schedulable_id' => $data['schedulable_id'],
                    'timeline' => $timeline,
                    'total' => count($timeline),
                    'breakdown' => [
                        'appointments' => count($data['appointments']),
                        'tasks' => count($data['tasks']),
                        'events' => count($data['events']),
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to fetch timeline.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
