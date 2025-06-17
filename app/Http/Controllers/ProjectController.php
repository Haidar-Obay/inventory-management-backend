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
        return response()->json($project->load(['customer', 'jobs']));
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
                \DB::raw("CONCAT(customers.first_name, ' ', customers.last_name) as customer_name"),
                'projects.start_date',
                'projects.end_date',
                'projects.expected_date'
            ])
            ->get();

        if ($projects->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'No data to export',
            ]);
        }

        $title = 'Project Report';
        $headers = [
            'id' => 'ID',
            'name' => 'Name',
            'customer_name' => 'Customer',
            'start_date' => 'Start Date',
            'end_date' => 'End Date',
            'expected_date' => 'Expected Date'
        ];
        $data = $projects->toArray();

        $pdf = $pdfService->generatePdf($title, $headers, $data);
        return $pdf->download('Projects.pdf');
    }

    public function import(Request $request)
    {
        $tenantId = tenant('id');
        $request->validate([
            'file' => 'required|mimes:xlsx,xls,csv|max:2048'
        ]);

        try {
            $import = new DynamicExcelImport(Project::class);
            Excel::import($import, $request->file('file'));
            app('cache')->store('database')->forget("tenant_{$tenantId}_projects");
                return response()->json([
                'status' => true,
                'message' => 'Import successful',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Import failed: ' . $e->getMessage(),
            ]);
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
                    'name' => $project->name
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
