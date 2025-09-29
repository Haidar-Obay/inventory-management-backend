<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Http\Requests\Project\StoreProjectRequest;
use App\Http\Requests\Project\UpdateProjectRequest;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Imports\DynamicExcelImport;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use App\Models\Customer;

class ProjectController extends Controller
{
    public function index()
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_projects";

        $projects = app('cache')->store('database')->get($key);

        if (!$projects) {
            $projects = Project::with('customer')->get();

            app('cache')->store('database')->forever($key, $projects);
        }

        return response()->json([
            'status' => true,
            'message' => 'Projects fetched successfully.',
            'data' => $projects,
        ]);
    }

    public function store(StoreProjectRequest $request)
    {
        $tenantId = tenant('id');
        $project = Project::create($request->validated());
        app('cache')->store('database')->forget("tenant_{$tenantId}_projects");
        return response()->json([
            'status' => true,
            'message' => 'Project created successfully.',
            'data' => $project,
        ], 201);
    }

    public function show(Project $project)
    {
        $project->load(['customer', 'jobs']);
        
        return response()->json([
            'status' => true,
            'message' => 'Project details fetched successfully.',
            'data' => $project,
        ]);
    }

    public function update(UpdateProjectRequest $request, Project $project)
    {
        $tenantId = tenant('id');
        $project->update($request->validated());
        app('cache')->store('database')->forget("tenant_{$tenantId}_projects");
        return response()->json([
            'status' => true,
            'message' => 'Project updated successfully.',
            'data' => $project,
        ]);
    }

    public function destroy(Project $project)
    {
        $tenantId = tenant('id');
        if ($project->jobs()->exists()) {
            return response()->json([
                'status' => false,
                'message' => 'Cannot delete project with associated jobs',
            ], 422);
        }

        $project->delete();
        app('cache')->store('database')->forget("tenant_{$tenantId}_projects");
        return response()->json([
            'status' => true,
            'message' => 'Project deleted successfully.'
        ]);
    }

    public function bulkDelete(Request $request)
    {
        $tenantId = tenant('id');
        $ids = $request->input('ids');

        $projectsWithJobs = Project::whereIn('id', $ids)
            ->whereHas('jobs')
            ->pluck('id');

        if ($projectsWithJobs->isNotEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'Some projects have associated jobs and cannot be deleted',
                'projects' => $projectsWithJobs
            ]);
        }

        Project::whereIn('id', $ids)->delete();
        app('cache')->store('database')->forget("tenant_{$tenantId}_projects");
        return response()->json([
            'status' => true,
            'message' => 'Projects deleted successfully.',
        ]);
    }

    public function exportExcel()
    {
        $projects = Project::query()
            ->leftJoin('customers', 'projects.customer_id', '=', 'customers.id')
            ->select([
                'projects.id',
                'projects.name',
                DB::raw("CONCAT(customers.first_name, ' ', customers.last_name) as customer_name"),
                'projects.start_date',
                'projects.end_date',
                'projects.expected_date'
            ]);

        $collection = $projects->get();

        if ($collection->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'No data to export',
            ]);
        }

        $columns = ['id', 'name', 'customer_name', 'start_date', 'end_date', 'expected_date'];
        $headings = ['ID', 'Name', 'Customer', 'Start Date', 'End Date', 'Expected Date'];

        return Excel::download(new Export($projects, $columns, $headings), 'projects.xlsx');
    }

    public function exportPdf(ExportPDF $pdfService)
    {
        $projects = Project::query()
            ->leftJoin('customers', 'projects.customer_id', '=', 'customers.id')
            ->select([
                'projects.id',
                'projects.name',
                DB::raw("CONCAT(customers.first_name, ' ', customers.last_name) as customer_name"),
                'projects.start_date',
                'projects.end_date',
                'projects.expected_date',
                'created_at', 'updated_at'])
            ->get();

        if ($projects->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'No data to export',
            ]);
        }

        $title = 'Project Report';
        $headers = ['id' => 'ID', 'name' => 'Name', 'customer_name' => 'Customer', 'start_date' => 'Start Date', 'end_date' => 'End Date', 'expected_date' => 'Expected Date', 'created_at' => 'Created At', 'updated_at' => 'Updated At'];
        $data = $projects->toArray();

        $pdf = $pdfService->generatePdf($title, $headers, $data);
        return $pdf->download('Projects.pdf');
    }

    public function importFromExcel(Request $request)
    {
        $tenantId = tenant('id');
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
            Project::truncate();
        }

        // If type is 'mapping', use provided mapping, else use default
        $mapping = $request->input('mapping');

        try {
            $import = new DynamicExcelImport(
                Project::class,
                ['name', 'start_date', 'end_date', 'expected_date', 'customer_id'],
                function ($row) {
                    foreach ($row as $k => $v) { if (is_string($v)) { $row[$k] = trim($v); } }
                    $errors = [];
                    if (($row['name'] ?? '') === '') $errors[] = 'Missing name';
                    if (($row['customer_id'] ?? '') === '') $errors[] = 'Missing customer_id';
                    // Validate foreign keys in a readable way so rows are skipped instead of causing SQL errors
                    if (($row['customer_id'] ?? '') !== '') {
                        $customerId = $row['customer_id'];
                        if (!Customer::where('id', $customerId)->exists()) {
                            $errors[] = "Invalid customer_id: {$customerId} not found";
                        }
                    }
                    return $errors;
                },
                function ($row) {
                    foreach ($row as $k => $v) { if (is_string($v)) { $row[$k] = trim($v); } }

                    $parseDate = function ($value) {
                        if ($value === null || $value === '') { return null; }
                        if (is_numeric($value)) {
                            try {
                                $dt = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float)$value);
                                return \Carbon\Carbon::instance($dt)->format('Y-m-d');
                            } catch (\Throwable $e) {}
                        }
                        $tryFormats = ['n/j/Y', 'm/d/Y', 'Y-m-d'];
                        foreach ($tryFormats as $fmt) {
                            try { return \Carbon\Carbon::createFromFormat($fmt, (string)$value)->format('Y-m-d'); } catch (\Throwable $e) {}
                        }
                        try { return \Carbon\Carbon::parse((string)$value)->format('Y-m-d'); } catch (\Throwable $e) { return null; }
                    };

                    return [
                        'name' => $row['name'] ?? null,
                        'description' => $row['description'] ?? null,
                        'customer_id' => $row['customer_id'] ?? null,
                        'start_date' => $parseDate($row['start_date'] ?? null),
                        'end_date' => $parseDate($row['end_date'] ?? null),
                        'expected_date' => $parseDate($row['expected_date'] ?? null),
                        'status' => $row['status'] ?? 'active',
                    ];
                },
                true // Enable header validation
            );
            
            Excel::import($import, $request->file('file'));
            
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
            
            app('cache')->store('database')->forget("tenant_{$tenantId}_projects");

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
        } catch (QueryException $e) {
            // Handle FK violations and other DB errors with user-friendly messages
            $message = $e->getMessage();
            $readable = 'Import failed due to invalid related data.';
            // Postgres SQLSTATE 23503 (foreign key violation) or generic FK text
            if (str_contains($message, 'SQLSTATE[23503]') || str_contains(strtolower($message), 'foreign key')) {
                $readable = 'Import failed: One or more rows reference a customer that does not exist. Please verify customer_id values.';
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
    }

    

    public function getCustomerProjects($customerId)
    {
        $tenantId = tenant('id');
        $cacheKey = "tenant_{$tenantId}_customer_projects_{$customerId}";

        $projects = app('cache')->store('database')->get($cacheKey);

        if (!$projects) {
            $projects = Project::where('customer_id', $customerId)->get();
            app('cache')->store('database')->forever($cacheKey, $projects);
        }

        return response()->json([
            'status' => true,
            'message' => 'Projects fetched successfully.',
            'data' => $projects,
        ]);
    }

    public function getNames()
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_project_names";

        // Get projects directly first to ensure we have data
        $projects = Project::select('id', 'name')
            ->orderBy('name')
            ->get()
            ->map(function ($project) {
                return [
                    'id' => $project->id,
                    'name' => $project->name,
                    'created_at' => $project->created_at,
                    'updated_at' => $project->updated_at,
                ];
            });

        // Store in cache
        app('cache')->store('database')->forever($key, $projects);

        // Retrieve from cache to verify
        $cachedProjects = app('cache')->store('database')->get($key);

        return response()->json([
            'status' => true,
            'message' => 'Project names fetched successfully.',
            'data' => $cachedProjects ?? $projects, // Fallback to direct data if cache fails
        ]);
    }
}
