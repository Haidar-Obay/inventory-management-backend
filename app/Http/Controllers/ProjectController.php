<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Http\Requests\Project\StoreProjectRequest;
use App\Http\Requests\Project\UpdateProjectRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Imports\DynamicExcelImport;

class ProjectController extends Controller
{
    public function index()
    {
        $projects = Cache::remember('projects', 60, function () {
            return Project::with('customer')->get();
        });

        return response()->json($projects);
    }

    public function store(StoreProjectRequest $request)
    {
        $project = Project::create($request->validated());
        Cache::forget('projects');
        return response()->json($project, 201);
    }

    public function show(Project $project)
    {
        return response()->json($project->load(['customer', 'jobs']));
    }

    public function update(UpdateProjectRequest $request, Project $project)
    {
        $project->update($request->validated());
        Cache::forget('projects');
        return response()->json($project);
    }

    public function destroy(Project $project)
    {
        if ($project->jobs()->exists()) {
            return response()->json(['message' => 'Cannot delete project with associated jobs'], 422);
        }

        $project->delete();
        Cache::forget('projects');
        return response()->json(null, 204);
    }

    public function bulkDelete(Request $request)
    {
        $ids = $request->input('ids');

        $projectsWithJobs = Project::whereIn('id', $ids)
            ->whereHas('jobs')
            ->pluck('id');

        if ($projectsWithJobs->isNotEmpty()) {
            return response()->json([
                'message' => 'Some projects have associated jobs and cannot be deleted',
                'projects' => $projectsWithJobs
            ], 422);
        }

        Project::whereIn('id', $ids)->delete();
        Cache::forget('projects');
        return response()->json(null, 204);
    }

    public function exportExcel()
    {
        $projects = Project::with('customer')->get();

        if ($projects->isEmpty()) {
            return response()->json(['message' => 'No data to export'], 404);
        }

        $export = new Export($projects, [
            'id' => 'ID',
            'name' => 'Name',
            'customer.name' => 'Customer',
            'start_date' => 'Start Date',
            'end_date' => 'End Date',
            'expected_date' => 'Expected Date'
        ]);

        return Excel::download($export, 'projects.xlsx');
    }

    public function exportPdf()
    {
        $projects = Project::with('customer')->get();

        if ($projects->isEmpty()) {
            return response()->json(['message' => 'No data to export'], 404);
        }

        $export = new ExportPDF($projects, [
            'id' => 'ID',
            'name' => 'Name',
            'customer.name' => 'Customer',
            'start_date' => 'Start Date',
            'end_date' => 'End Date',
            'expected_date' => 'Expected Date'
        ]);

        return $export->download('projects.pdf');
    }

    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:xlsx,xls,csv|max:2048'
        ]);

        try {
            $import = new DynamicExcelImport(Project::class);
            Excel::import($import, $request->file('file'));
            Cache::forget('projects');
            return response()->json(['message' => 'Import successful']);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Import failed: ' . $e->getMessage()], 422);
        }
    }

    public function getCustomerProjects($customerId)
    {
        $tenantId = tenant('id');
        $cacheKey = "customer_projects_{$customerId}_{$tenantId}";

        return Cache::remember($cacheKey, 3600, function () use ($customerId) {
            return Project::where('customer_id', $customerId)->get();
        });
    }
}
