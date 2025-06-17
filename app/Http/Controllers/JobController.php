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

        $columns = ['id', 'description', 'project_name', 'start_date', 'expected_date', 'end_date'];
        $headings = ['ID', 'Description', 'Project', 'Start Date', 'Expected Date', 'End Date'];

        return Excel::download(new Export($jobs, $columns, $headings), 'jobs.xlsx');
    }

    public function exportPdf(ExportPDF $pdfService)
    {
        $jobs = Job::with('project')->get();

        if ($jobs->isEmpty()) {
            return response()->json(['message' => 'No jobs found.'], 404);
        }

        $title = 'Jobs Report';
        $headers = [
            'id' => 'Job ID',
            'description' => 'Description',
            'project.name' => 'Project',
            'start_date' => 'Start Date',
            'expected_date' => 'Expected Date',
            'end_date' => 'End Date'
        ];
        $data = $jobs->toArray();

        $pdf = $pdfService->generatePdf($title, $headers, $data);
        return $pdf->download('Jobs.pdf');
    }

    public function importFromExcel(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv',
        ]);

        $import = new DynamicExcelImport(
            Job::class,
            ['description', 'project_id', 'start_date', 'expected_date', 'end_date'],
            function ($row) {
                $errors = [];

                if (empty($row['description'])) {
                    $errors[] = 'Missing description';
                }
                if (empty($row['project_id'])) {
                    $errors[] = 'Missing project';
                }
                if (empty($row['start_date'])) {
                    $errors[] = 'Missing start date';
                }
                if (empty($row['expected_date'])) {
                    $errors[] = 'Missing expected date';
                }

                return $errors;
            },
            function ($row) {
                return [
                    'description' => $row['description'],
                    'project_id' => $row['project_id'],
                    'start_date' => $row['start_date'],
                    'expected_date' => $row['expected_date'],
                    'end_date' => $row['end_date'] ?? null,
                ];
            }
        );

        Excel::import($import, $request->file('file'));

        app('cache')->store('database')->forget('tenant_' . tenant('id') . '_jobs');

        return response()->json([
            'success' => true,
            'rows_imported' => $import->getImportedCount(),
            'rows_skipped_count' => $import->getSkippedCount(),
            'skipped_rows' => $import->getSkippedRows(),
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
