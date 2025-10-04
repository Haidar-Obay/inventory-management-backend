<?php

namespace App\Http\Controllers;

use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Http\Requests\Assignment\StoreAssignmentRequest;
use App\Http\Requests\Assignment\UpdateAssignmentRequest;
use App\Imports\DynamicExcelImport;
use App\Models\Assignment;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class AssignmentController extends Controller
{
    public function index()
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_assignments";

        $assignments = app('cache')->store('database')->get($key);

        if (! $assignments) {
            $assignments = Assignment::with([
                'asset:id,name,type,status,section_id',
                'asset.section:id,name,room_id',
                'asset.section.room:id,name,location',
                'user:id,name,email',
            ])->orderBy('start_at', 'desc')->get();

            app('cache')->store('database')->forever($key, $assignments);
        }

        return response()->json([
            'status' => true,
            'message' => 'Assignments fetched successfully.',
            'data' => $assignments,
        ]);
    }

    public function store(StoreAssignmentRequest $request)
    {
        $validated = $request->validated();

        // Check for overlapping assignments
        $overlapping = Assignment::overlapping(
            $validated['asset_id'],
            $validated['start_at'],
            $validated['end_at'] ?? null
        )->exists();

        if ($overlapping) {
            return response()->json([
                'status' => false,
                'message' => 'This asset is already assigned during the specified time period.',
            ], 422);
        }

        $assignment = Assignment::create($validated);

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_assignments");

        return response()->json([
            'status' => true,
            'message' => 'Assignment created successfully.',
            'data' => $assignment->load([
                'asset:id,name,type,status,section_id',
                'asset.section:id,name,room_id',
                'asset.section.room:id,name,location',
                'user:id,name,email',
            ]),
        ], 201);
    }

    public function show(Assignment $assignment)
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_assignment_{$assignment->id}";

        $cachedAssignment = app('cache')->store('database')->get($key);

        if (! $cachedAssignment) {
            $cachedAssignment = $assignment->load([
                'asset:id,name,type,status,section_id',
                'asset.section:id,name,room_id',
                'asset.section.room:id,name,location',
                'user:id,name,email',
            ]);

            app('cache')->store('database')->forever($key, $cachedAssignment);
        }

        return response()->json([
            'status' => true,
            'message' => 'Assignment details fetched successfully.',
            'data' => $cachedAssignment,
        ]);
    }

    public function update(UpdateAssignmentRequest $request, Assignment $assignment)
    {
        $validated = $request->validated();

        // Check for overlapping assignments (excluding current assignment)
        if (isset($validated['asset_id']) || isset($validated['start_at']) || isset($validated['end_at'])) {
            $assetId = $validated['asset_id'] ?? $assignment->asset_id;
            $startAt = $validated['start_at'] ?? $assignment->start_at;
            $endAt = $validated['end_at'] ?? $assignment->end_at;

            $overlapping = Assignment::overlapping(
                $assetId,
                $startAt,
                $endAt,
                $assignment->id
            )->exists();

            if ($overlapping) {
                return response()->json([
                    'status' => false,
                    'message' => 'This asset is already assigned during the specified time period.',
                ], 422);
            }
        }

        $assignment->update($validated);

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_assignments");
        app('cache')->store('database')->forget("tenant_{$tenantId}_assignment_{$assignment->id}");

        return response()->json([
            'status' => true,
            'message' => 'Assignment updated successfully.',
            'data' => $assignment->load([
                'asset:id,name,type,status,section_id',
                'asset.section:id,name,room_id',
                'asset.section.room:id,name,location',
                'user:id,name,email',
            ]),
        ]);
    }

    public function destroy(Assignment $assignment)
    {
        $assignment->delete();

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_assignments");
        app('cache')->store('database')->forget("tenant_{$tenantId}_assignment_{$assignment->id}");

        return response()->json([
            'status' => true,
            'message' => 'Assignment deleted successfully.',
        ]);
    }

    public function bulkDelete(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:assignments,id',
        ]);

        $tenantId = tenant('id');
        $skipped = [];
        $deleted = 0;

        foreach ($request->ids as $id) {
            try {
                $deleted += Assignment::where('id', $id)->delete();
                app('cache')->store('database')->forget("tenant_{$tenantId}_assignment_{$id}");
            } catch (\Illuminate\Database\QueryException $e) {
                $skipped[] = ['id' => $id, 'reason' => $e->getMessage()];
            }
        }

        app('cache')->store('database')->forget("tenant_{$tenantId}_assignments");

        return response()->json([
            'status' => true,
            'message' => 'Bulk delete completed.',
            'data' => [
                'deleted_count' => $deleted,
                'skipped' => $skipped,
            ],
        ]);
    }

    public function exportExcell()
    {
        $assignments = Assignment::with([
            'asset:id,name,type,status,section_id',
            'asset.section:id,name,room_id',
            'asset.section.room:id,name,location',
            'user:id,name,email',
        ])->orderBy('start_at', 'desc');
        $collection = $assignments->get();

        if ($collection->isEmpty()) {
            return response()->json(['message' => 'No assignments found.'], 404);
        }

        $columns = ['id', 'asset_id', 'user_id', 'start_at', 'end_at', 'status', 'notes', 'created_at', 'updated_at'];
        $headings = ['ID', 'Asset ID', 'User ID', 'Start At', 'End At', 'Status', 'Notes', 'Created At', 'Updated At'];

        return Excel::download(new Export($assignments, $columns, $headings), 'assignments.xlsx');
    }

    public function exportPdf()
    {
        $assignments = Assignment::with([
            'asset:id,name,type,status,section_id',
            'asset.section:id,name,room_id',
            'asset.section.room:id,name,location',
            'user:id,name,email',
        ])->select('id', 'asset_id', 'user_id', 'start_at', 'end_at', 'status', 'notes')->get();

        if ($assignments->isEmpty()) {
            return response()->json(['message' => 'No assignments found.'], 404);
        }

        $title = 'Assignment Report';
        $headers = [
            'id' => 'Assignment ID',
            'asset_id' => 'Asset ID',
            'user_id' => 'User ID',
            'start_at' => 'Start At',
            'end_at' => 'End At',
            'status' => 'Status',
            'notes' => 'Notes',
        ];
        $data = $assignments->toArray();

        $pdfService = new ExportPDF;
        $pdf = $pdfService->generatePdf($title, $headers, $data);

        return $pdf->download('Assignments.pdf');
    }

    public function importFromExcel(Request $request)
    {
        $request->validate([
            'file' => [
                'required',
                'file',
                'mimes:xlsx,xls,csv,txt,text/plain,text/csv,application/csv',
            ],
            'type' => 'nullable|string|in:fresh,mapping',
            'mapping' => 'nullable|array',
        ], [
            'file.mimes' => 'The file field must be a file of type: xlsx, xls, csv',
        ]);

        // If type is 'fresh', delete all records first
        if ($request->input('type') === 'fresh') {
            Assignment::truncate();
        }

        // If type is 'mapping', use provided mapping, else use default
        $mapping = $request->input('mapping');
        $fields = $mapping ? array_values($mapping) : ['asset_id', 'user_id', 'start_at', 'end_at', 'status', 'notes'];

        $import = new DynamicExcelImport(
            Assignment::class,
            $fields,
            function ($row) use ($mapping) {
                $errors = [];
                $assetIdKey = $mapping ? array_search('asset_id', $mapping) : 'asset_id';
                $userIdKey = $mapping ? array_search('user_id', $mapping) : 'user_id';

                if (empty($row[$assetIdKey])) {
                    $errors[] = 'Missing asset_id';
                }
                if (empty($row[$userIdKey])) {
                    $errors[] = 'Missing user_id';
                }
                $startAtKey = $mapping ? array_search('start_at', $mapping) : 'start_at';
                if (empty($row[$startAtKey])) {
                    $errors[] = 'Missing start_at';
                }

                return $errors;
            },
            function ($row) use ($mapping) {
                $assetIdKey = $mapping ? array_search('asset_id', $mapping) : 'asset_id';
                $userIdKey = $mapping ? array_search('user_id', $mapping) : 'user_id';
                $startAtKey = $mapping ? array_search('start_at', $mapping) : 'start_at';
                $endAtKey = $mapping ? array_search('end_at', $mapping) : 'end_at';
                $statusKey = $mapping ? array_search('status', $mapping) : 'status';
                $notesKey = $mapping ? array_search('notes', $mapping) : 'notes';

                return [
                    'asset_id' => $row[$assetIdKey] ?? null,
                    'user_id' => $row[$userIdKey] ?? null,
                    'start_at' => $row[$startAtKey] ?? null,
                    'end_at' => $row[$endAtKey] ?? null,
                    'status' => $row[$statusKey] ?? 'active',
                    'notes' => $row[$notesKey] ?? null,
                ];
            },
            true // Enable header validation
        );

        Excel::import($import, $request->file('file'));

        // Check if headers were valid
        if (! $import->areHeadersValid()) {
            $headerResult = $import->getHeaderValidationResult();

            return response()->json([
                'success' => false,
                'message' => 'Invalid Excel file headers',
                'header_validation' => $headerResult,
                'errors' => [
                    'missing_headers' => $headerResult['missing'],
                    'extra_headers' => $headerResult['extra'],
                    'expected_headers' => $headerResult['expected_headers'],
                    'actual_headers' => $headerResult['excel_headers'],
                ],
            ], 422);
        }

        app('cache')->store('database')->forget('tenant_'.tenant('id').'_assignments');

        return response()->json([
            'status' => true,
            'message' => 'Import completed successfully.',
            'data' => [
                'rows_imported' => $import->getImportedCount(),
                'rows_skipped_count' => $import->getSkippedCount(),
                'skipped_rows' => $import->getSkippedRows(),
            ],
        ]);
    }

    public function byAsset($assetId)
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_asset_{$assetId}_assignments";

        $assignments = app('cache')->store('database')->get($key);

        if (! $assignments) {
            $assignments = Assignment::byAsset($assetId)
                ->with([
                    'asset:id,name,type,status,section_id',
                    'asset.section:id,name,room_id',
                    'asset.section.room:id,name,location',
                    'user:id,name,email',
                ])
                ->orderBy('start_at', 'desc')
                ->get();

            app('cache')->store('database')->forever($key, $assignments);
        }

        return response()->json([
            'status' => true,
            'message' => 'Assignments for asset fetched successfully.',
            'data' => $assignments,
        ]);
    }

    public function byUser($userId)
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_user_{$userId}_assignments";

        $assignments = app('cache')->store('database')->get($key);

        if (! $assignments) {
            $assignments = Assignment::byUser($userId)
                ->with([
                    'asset:id,name,type,status,section_id',
                    'asset.section:id,name,room_id',
                    'asset.section.room:id,name,location',
                    'user:id,name,email',
                ])
                ->orderBy('start_at', 'desc')
                ->get();

            app('cache')->store('database')->forever($key, $assignments);
        }

        return response()->json([
            'status' => true,
            'message' => 'Assignments for user fetched successfully.',
            'data' => $assignments,
        ]);
    }

    public function active()
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_active_assignments";

        $assignments = app('cache')->store('database')->get($key);

        if (! $assignments) {
            $assignments = Assignment::active()
                ->with([
                    'asset:id,name,type,status,section_id',
                    'asset.section:id,name,room_id',
                    'asset.section.room:id,name,location',
                    'user:id,name,email',
                ])
                ->orderBy('start_at', 'desc')
                ->get();

            app('cache')->store('database')->forever($key, $assignments);
        }

        return response()->json([
            'status' => true,
            'message' => 'Active assignments fetched successfully.',
            'data' => $assignments,
        ]);
    }
}
