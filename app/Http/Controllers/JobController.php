<?php

namespace App\Http\Controllers;

use App\Models\Job;
use App\Http\Requests\Job\StoreJobRequest;
use App\Http\Requests\Job\UpdateJobRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Imports\DynamicExcelImport;

class JobController extends Controller
{
    public function index()
    {
        $jobs = Cache::remember('jobs', 60, function () {
            return Job::with('project')->get();
        });

        return response()->json($jobs);
    }

    public function store(StoreJobRequest $request)
    {
        $job = Job::create($request->validated());
        Cache::forget('jobs');
        return response()->json($job, 201);
    }

    public function show(Job $job)
    {
        return response()->json($job->load('project'));
    }

    public function update(UpdateJobRequest $request, Job $job)
    {
        $job->update($request->validated());
        Cache::forget('jobs');
        return response()->json($job);
    }

    public function destroy(Job $job)
    {
        $job->delete();
        Cache::forget('jobs');
        return response()->json(null, 204);
    }

    public function bulkDelete(Request $request)
    {
        $ids = $request->input('ids');
        Job::whereIn('id', $ids)->delete();
        Cache::forget('jobs');
        return response()->json(null, 204);
    }

    public function exportExcel()
    {
        $jobs = Job::with('project')->get();

        if ($jobs->isEmpty()) {
            return response()->json(['message' => 'No data to export'], 404);
        }

        $export = new Export($jobs, [
            'id' => 'ID',
            'description' => 'Description',
            'project.name' => 'Project',
            'start_date' => 'Start Date',
            'end_date' => 'End Date',
            'expected_date' => 'Expected Date'
        ]);

        return Excel::download($export, 'jobs.xlsx');
    }

    public function exportPdf()
    {
        $jobs = Job::with('project')->get();

        if ($jobs->isEmpty()) {
            return response()->json(['message' => 'No data to export'], 404);
        }

        $export = new ExportPDF($jobs, [
            'id' => 'ID',
            'description' => 'Description',
            'project.name' => 'Project',
            'start_date' => 'Start Date',
            'end_date' => 'End Date',
            'expected_date' => 'Expected Date'
        ]);

        return $export->download('jobs.pdf');
    }

    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:xlsx,xls,csv|max:2048'
        ]);

        try {
            $import = new DynamicExcelImport(Job::class);
            Excel::import($import, $request->file('file'));
            Cache::forget('jobs');
            return response()->json(['message' => 'Import successful']);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Import failed: ' . $e->getMessage()], 422);
        }
    }

    public function getProjectJobs($projectId)
    {
        $tenantId = tenant('id');
        $cacheKey = "project_jobs_{$projectId}_{$tenantId}";

        return Cache::remember($cacheKey, 3600, function () use ($projectId) {
            return Job::where('project_id', $projectId)->get();
        });
    }
}
