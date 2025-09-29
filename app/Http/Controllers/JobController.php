<?php

namespace App\Http\Controllers;

use App\Models\Job;
use App\Http\Requests\Job\StoreJobRequest;
use App\Http\Requests\Job\UpdateJobRequest;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Imports\DynamicExcelImport;
use Illuminate\Database\QueryException;
use App\Models\Project;

class JobController extends Controller
{
    public function index()
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_jobs";

        $jobs = app('cache')->store('database')->get($key);

        if (!$jobs) {
            $jobs = Job::with('project')->orderBy('created_at', 'desc')->get();
            app('cache')->store('database')->forever($key, $jobs);
        }

        return response()->json([
            'status' => true,
            'message' => 'Jobs fetched successfully.',
            'data' => $jobs,
        ]);
    }

    public function store(StoreJobRequest $request)
    {
        $job = Job::create($request->validated());

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_jobs");

        return response()->json([
            'status' => true,
            'message' => 'Job created successfully.',
            'data' => $job,
        ], 201);
    }

    public function show(Job $job)
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_job_{$job->id}";

        $cachedJob = app('cache')->store('database')->get($key);

        if (!$cachedJob) {
            $cachedJob = $job->load('project');
            app('cache')->store('database')->forever($key, $cachedJob);
        }

        return response()->json([
            'status' => true,
            'message' => 'Job details fetched successfully.',
            'data' => $cachedJob,
        ]);
    }

    public function update(UpdateJobRequest $request, Job $job)
    {
        $job->update($request->validated());

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_jobs");
        app('cache')->store('database')->forget("tenant_{$tenantId}_job_{$job->id}");

        return response()->json([
            'status' => true,
            'message' => 'Job updated successfully.',
            'data' => $job,
        ]);
    }

    public function destroy(Job $job)
    {
        $job->delete();

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_jobs");
        app('cache')->store('database')->forget("tenant_{$tenantId}_job_{$job->id}");

        return response()->json([
            'status' => true,
            'message' => 'Job deleted successfully.',
        ]);
    }

    public function bulkDelete(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:projects_jobs,id',
        ]);

        $tenantId = tenant('id');
        $skipped = [];
        $deleted = 0;

        foreach ($request->ids as $id) {
            try {
                $deleted += Job::where('id', $id)->delete();
                app('cache')->store('database')->forget("tenant_{$tenantId}_job_{$id}");
            } catch (\Illuminate\Database\QueryException $e) {
                $skipped[] = ['id' => $id, 'reason' => $e->getMessage()];
            }
        }

        app('cache')->store('database')->forget("tenant_{$tenantId}_jobs");

        return response()->json([
            'message' => 'Bulk delete completed.',
            'deleted_count' => $deleted,
            'skipped' => $skipped,
        ]);
    }

    public function exportExcell()
    {
        $jobs = Job::select([
            'projects_jobs.id',
            'projects_jobs.code',
            'projects_jobs.description',
            'projects.name as project_name',
            'projects_jobs.start_date',
            'projects_jobs.expected_date',
            'projects_jobs.end_date'
        ])
        ->join('projects', 'projects_jobs.project_id', '=', 'projects.id');

        if ($jobs->count() === 0) {
            return response()->json(['message' => 'No jobs found.'], 404);
        }

        $columns = ['id', 'description', 'project_name', 'start_date', 'expected_date', 'end_date',
            'created_at',
            'updated_at'];
        $headings = ['ID', 'Description', 'Project', 'Start Date', 'Expected Date', 'End Date',
            'Created At', 'Updated At'];

        return Excel::download(new Export($jobs, $columns, $headings), 'jobs.xlsx');
    }

    public function exportPdf(ExportPDF $pdfService)
    {
        $jobs = Job::with('project')->select('id', 'code', 'description', 'project_id', 'start_date', 'expected_date', 'end_date', 'created_at', 'updated_at')->get();

        if ($jobs->isEmpty()) {
            return response()->json(['message' => 'No jobs found.'], 404);
        }

        $title = 'Jobs Report';
        $headers = [
            'id' => 'Job ID',
            'code' => 'Job Code',
            'description' => 'Description',
            'project.name' => 'Project',
            'start_date' => 'Start Date',
            'expected_date' => 'Expected Date',
            'end_date' => 'End Date',
            'created_at' => 'Created At',
            'updated_at' => 'Updated At'
        ];
        $data = $jobs->toArray();

        $pdf = $pdfService->generatePdf($title, $headers, $data);
        return $pdf->download('Jobs.pdf');
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
            // Get model class from the import
            Job::truncate();
        }

        // If type is 'mapping', use provided mapping, else use default
        $mapping = $request->input('mapping');

        $import = new DynamicExcelImport(
            Job::class,
            ['code', 'description', 'project_id', 'start_date', 'expected_date', 'end_date'],
            function ($row) {
                foreach ($row as $k => $v) { if (is_string($v)) { $row[$k] = trim($v); } }
                $errors = [];

                // Helper to validate date inputs
                $isValidDate = function ($value) {
                    if ($value === null || $value === '') { return false; }
                    // Excel serial number
                    if (is_numeric($value)) { return true; }
                    // Common formats m/d/Y or mm/dd/YYYY
                    try { \Carbon\Carbon::createFromFormat('n/j/Y', (string)$value); return true; } catch (\Throwable $e) {}
                    try { \Carbon\Carbon::createFromFormat('m/d/Y', (string)$value); return true; } catch (\Throwable $e) {}
                    try { \Carbon\Carbon::parse((string)$value); return true; } catch (\Throwable $e) {}
                    return false;
                };

                if (($row['description'] ?? '') === '') {
                    $errors[] = 'Missing description';
                }
                if (($row['project_id'] ?? '') === '') {
                    $errors[] = 'Missing project';
                } else {
                    $projectId = $row['project_id'];
                    if (!Project::where('id', $projectId)->exists()) {
                        $errors[] = "Invalid project_id: {$projectId} not found";
                    }
                }
                if (($row['start_date'] ?? '') === '' || !$isValidDate($row['start_date'])) {
                    $errors[] = 'Invalid start_date (use m/d/Y or Excel date)';
                }
                if (($row['expected_date'] ?? '') === '' || !$isValidDate($row['expected_date'])) {
                    $errors[] = 'Invalid expected_date (use m/d/Y or Excel date)';
                }
                if (($row['end_date'] ?? '') !== '' && !$isValidDate($row['end_date'])) {
                    $errors[] = 'Invalid end_date (use m/d/Y or Excel date)';
                }

                return $errors;
            },
            function ($row) {
                foreach ($row as $k => $v) { if (is_string($v)) { $row[$k] = trim($v); } }

                $parseDate = function ($value) {
                    if ($value === null || $value === '') { return null; }
                    // Excel serial number
                    if (is_numeric($value)) {
                        try {
                            $dt = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float)$value);
                            return \Carbon\Carbon::instance($dt)->format('Y-m-d');
                        } catch (\Throwable $e) { /* fall through */ }
                    }
                    $tryFormats = ['n/j/Y', 'm/d/Y', 'Y-m-d'];
                    foreach ($tryFormats as $fmt) {
                        try { return \Carbon\Carbon::createFromFormat($fmt, (string)$value)->format('Y-m-d'); } catch (\Throwable $e) {}
                    }
                    try { return \Carbon\Carbon::parse((string)$value)->format('Y-m-d'); } catch (\Throwable $e) { return null; }
                };

                return [
                    'code' => $row['code'] ?? null,
                    'description' => $row['description'] ?? null,
                    'project_id' => $row['project_id'] ?? null,
                    'start_date' => $parseDate($row['start_date'] ?? null),
                    'expected_date' => $parseDate($row['expected_date'] ?? null),
                    'end_date' => $parseDate($row['end_date'] ?? null),
                ];
            },
            true, // Enable header validation
            $request->input('type') === 'fresh' // Skip duplicate check when fresh
        );

        try {
            Excel::import($import, $request->file('file'));
        } catch (QueryException $e) {
            $message = $e->getMessage();
            $readable = 'Import failed due to invalid related data.';
            if (str_contains($message, 'SQLSTATE[23503]') || str_contains(strtolower($message), 'foreign key')) {
                $readable = 'Import failed: One or more rows reference a project that does not exist. Please verify project_id values.';
            }
            return response()->json([
                'status' => false,
                'message' => $readable,
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Import failed due to an unexpected error. Please check your file and try again.',
            ], 422);
        }

        // Check if headers were valid
        if (!$import->areHeadersValid()) {
            $headerResult = $import->getHeaderValidationResult();
            return response()->json([
                'success' => false,
                'message' => 'Invalid Excel file headers',
                'header_validation' => $headerResult,
                'errors' => [
                    'missing_headers' => $headerResult['missing'],
                    'extra_headers' => $headerResult['extra'],
                    'expected_headers' => $headerResult['expected_headers'],
                    'actual_headers' => $headerResult['excel_headers']
                ]
            ], 422);
        }

        app('cache')->store('database')->forget('tenant_' . tenant('id') . '_jobs');

        $imported = $import->getImportedCount();
        $skippedCount = $import->getSkippedCount();
        $skippedRows = $import->getSkippedRows();
        $totalProcessed = $imported + $skippedCount;

        $message = '';
        if ($imported > 0 && $skippedCount === 0) {
            $message = "Imported {$imported} row(s) successfully.";
        } elseif ($imported > 0 && $skippedCount > 0) {
            $message = "Partially imported: {$imported} row(s) added, {$skippedCount} row(s) skipped.";
        } elseif ($imported === 0 && $skippedCount > 0) {
            $message = 'No rows imported. All rows were skipped due to validation errors or duplicates.';
        } else {
            $message = 'No rows found to import.';
        }

        return response()->json([
            'success' => $imported > 0,
            'message' => $message,
            'rows_processed' => $totalProcessed,
            'rows_imported' => $imported,
            'rows_skipped_count' => $skippedCount,
            'skipped_rows' => $skippedRows,
        ]);
    }

    public function getProjectJobs($projectId)
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_project_{$projectId}_jobs";

        $jobs = app('cache')->store('database')->get($key);

        if (!$jobs) {
            $jobs = Job::where('project_id', $projectId)->get();
            app('cache')->store('database')->forever($key, $jobs);
        }

        return response()->json([
            'status' => true,
            'message' => 'Project jobs fetched successfully.',
            'data' => $jobs,
        ]);
    }
}
