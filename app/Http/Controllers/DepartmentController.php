<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Http\Requests\Department\StoreDepartmentRequest;
use App\Http\Requests\Department\UpdateDepartmentRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Imports\DynamicExcelImport;

class DepartmentController extends Controller
{
    public function index()
    {
        $departments = Cache::remember('departments', 60, function () {
            return Department::with('parent')->get();
        });

        return response()->json($departments);
    }

    public function store(StoreDepartmentRequest $request)
    {
        $department = Department::create($request->validated());
        Cache::forget('departments');
        return response()->json($department, 201);
    }

    public function show(Department $department)
    {
        return response()->json($department->load('parent', 'children'));
    }

    public function update(UpdateDepartmentRequest $request, Department $department)
    {
        $department->update($request->validated());
        Cache::forget('departments');
        return response()->json($department);
    }

    public function destroy(Department $department)
    {
        if ($department->hasSubDepartments()) {
            return response()->json(['message' => 'Cannot delete department with sub-departments'], 422);
        }

        $department->delete();
        Cache::forget('departments');
        return response()->json(null, 204);
    }

    public function bulkDelete(Request $request)
    {
        $ids = $request->input('ids');

        $departmentsWithChildren = Department::whereIn('id', $ids)
            ->whereHas('children')
            ->pluck('id');

        if ($departmentsWithChildren->isNotEmpty()) {
            return response()->json([
                'message' => 'Some departments have sub-departments and cannot be deleted',
                'departments' => $departmentsWithChildren
            ], 422);
        }

        Department::whereIn('id', $ids)->delete();
        Cache::forget('departments');
        return response()->json(null, 204);
    }

    public function exportExcel()
    {
        $departments = Department::with('parent')->get();

        if ($departments->isEmpty()) {
            return response()->json(['message' => 'No data to export'], 404);
        }

        $export = new Export($departments, [
            'id' => 'ID',
            'code' => 'Code',
            'name' => 'Name',
            'parent.code' => 'Parent Department',
            'is_inactive' => 'Status'
        ]);

        return Excel::download($export, 'departments.xlsx');
    }

    public function exportPdf()
    {
        $departments = Department::with('parent')->get();

        if ($departments->isEmpty()) {
            return response()->json(['message' => 'No data to export'], 404);
        }

        $export = new ExportPDF($departments, [
            'id' => 'ID',
            'code' => 'Code',
            'name' => 'Name',
            'parent.code' => 'Parent Department',
            'is_inactive' => 'Status'
        ]);

        return $export->download('departments.pdf');
    }

    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:xlsx,xls,csv|max:2048'
        ]);

        try {
            $import = new DynamicExcelImport(Department::class);
            Excel::import($import, $request->file('file'));
            Cache::forget('departments');
            return response()->json(['message' => 'Import successful']);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Import failed: ' . $e->getMessage()], 422);
        }
    }

    public function getSubDepartments($departmentId)
    {
        $tenantId = tenant('id');
        $cacheKey = "department_subs_{$departmentId}_{$tenantId}";

        return Cache::remember($cacheKey, 3600, function () use ($departmentId) {
            return Department::where('sub_department_of', $departmentId)
                ->with('parentDepartment')
                ->get();
        });
    }

    protected function validateNoCircularReference($parentId, $currentId = null)
    {
        if ($currentId && $parentId == $currentId) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'sub_department_of' => ['A department cannot be a sub-department of itself']
            ]);
        }

        $parent = Department::find($parentId);
        if ($parent && $parent->isSubDepartment()) {
            $ancestors = $this->getAncestors($parent);
            if (in_array($currentId, $ancestors)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'sub_department_of' => ['Circular reference detected in department hierarchy']
                ]);
            }
        }
    }

    protected function getAncestors($department)
    {
        $ancestors = [];
        while ($department->parentDepartment) {
            $ancestors[] = $department->parentDepartment->id;
            $department = $department->parentDepartment;
        }
        return $ancestors;
    }
}
