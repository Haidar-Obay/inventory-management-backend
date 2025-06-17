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
use Illuminate\Support\Facades\Log;

class DepartmentController extends Controller
{
    public function index()
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_departments";

        $departments = app('cache')->store('database')->get($key);

        if (!$departments) {
            $departments = Department::with('parent')->get();
            app('cache')->store('database')->forever($key, $departments);
        }

        return response()->json([
            'status' => true,
            'message' => 'Departments fetched successfully.',
            'data' => $departments,
        ]);
    }

    public function store(StoreDepartmentRequest $request)
    {
        $tenantId = tenant('id');
        $department = Department::create($request->validated());
        app('cache')->store('database')->forget("tenant_{$tenantId}_departments");
        return response()->json([
            'status' => true,
            'message' => 'Department created successfully.',
            'data' => $department,
        ], 201);
    }

    public function show($id)
    {
        try {
            $department = Department::findOrFail($id);
            return response()->json([
                'status' => true,
                'message' => 'Department fetched successfully.',
                'data' => $department,
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching department: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Department not found',
            ], 404);
        }
    }

    public function update(UpdateDepartmentRequest $request, Department $department)
    {
        $tenantId = tenant('id');
        $department->update($request->validated());
        app('cache')->store('database')->forget("tenant_{$tenantId}_departments");
        return response()->json([
            'status' => true,
            'message' => 'Department updated successfully.',
            'data' => $department,
        ]);
    }

    public function destroy(Department $department)
    {
        $tenantId = tenant('id');
        if ($department->hasSubDepartments()) {
            return response()->json([
                'status' => false,
                'message' => 'Cannot delete department with associated sub-departments',
            ], 422);
        }
        $department->delete();
        app('cache')->store('database')->forget("tenant_{$tenantId}_departments");
        return response()->json([
            'status' => true,
            'message' => 'Department deleted successfully.',
        ]);
    }

    public function bulkDelete(Request $request)
    {
        $tenantId = tenant('id');
        $ids = $request->input('ids');

        if (!$ids || !is_array($ids)) {
            return response()->json([
                'status' => false,
                'message' => 'No departments selected for deletion',
            ], 400);
        }

        try {
            foreach ($ids as $id) {
                $department = Department::findOrFail($id);
                if ($department->hasSubDepartments()) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Cannot delete department with sub-departments',
                    ], 422);
                }
                $department->delete();
                Cache::forget("departments_" . tenant('id'));
                Cache::forget("department_{$department->id}_" . tenant('id'));
            }

            return response()->json(null, 204);
        } catch (\Exception $e) {
            Log::error('Error in bulk delete: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to delete departments',
            ], 500);
        }
    }

    public function exportExcell()
    {
        $departments = Department::query()
            ->leftJoin('departments as parent', 'departments.sub_department_of', '=', 'parent.id')
            ->select([
                'departments.id',
                'departments.code',
                'departments.name',
                'parent.code as parent_code',
                'departments.active'
            ]);

        $collection = $departments->get();

        if ($collection->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'No data to export',
            ]);
        }

        $columns = ['id', 'code', 'name', 'parent_code', 'active'];
        $headings = ['ID', 'Code', 'Name', 'Parent Department', 'Status'];

        return Excel::download(new Export($departments, $columns, $headings), 'departments.xlsx');
    }

    public function exportPdf(ExportPDF $pdfService)
    {
        $departments = Department::query()
            ->leftJoin('departments as parent', 'departments.sub_department_of', '=', 'parent.id')
            ->select([
                'departments.id',
                'departments.code',
                'departments.name',
                'parent.code as parent_code',
                'departments.active'
            ])
            ->get();

        if ($departments->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'No data to export',
            ]);
        }

        $title = 'Departments Report';
        $headers = [
            'id' => 'ID',
            'code' => 'Code',
            'name' => 'Name',
            'parent_code' => 'Parent Department',
            'active' => 'Status'
        ];
        $data = $departments->toArray();

        $pdf = $pdfService->generatePdf($title, $headers, $data);
        return $pdf->download('Departments.pdf');
    }

    public function import(Request $request)
    {
        $tenantId = tenant('id');
        $request->validate([
            'file' => 'required|mimes:xlsx,xls,csv|max:2048'
        ]);

        try {
            $import = new DynamicExcelImport(Department::class);
            Excel::import($import, $request->file('file'));
            app('cache')->store('database')->forget("tenant_{$tenantId}_departments");
            return response()->json([
                'status' => true,
                'message' => 'Import successful',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Import failed: ' . $e->getMessage(),
            ], 422);
        }
    }

    public function getSubDepartments($departmentId)
    {
        $tenantId = tenant('id');
        $cacheKey = "department_subs_{$departmentId}_{$tenantId}";

        return Cache::remember($cacheKey, 3600, function () use ($departmentId) {
            return Department::where('sub_department_of', $departmentId)
                ->with('parent')
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

        try {
            $parent = Department::find($parentId);
            if ($parent && $parent->isSubDepartment()) {
                $ancestors = $this->getAncestors($parent);
                if (in_array($currentId, $ancestors)) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'sub_department_of' => ['Circular reference detected in department hierarchy']
                    ]);
                }
            }
        } catch (\Exception $e) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'sub_department_of' => ['An error occurred while validating the department hierarchy']
            ]);
        }
    }

    protected function getAncestors($department)
    {
        $ancestors = [];
        while ($department->parent) {
            $ancestors[] = $department->parent->id;
            $department = $department->parent;
        }
        return $ancestors;
    }

    public function getNames()
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_department_names";

        // Get departments directly first to ensure we have data
        $departments = Department::select('id', 'name')
            ->orderBy('name')
            ->get()
            ->map(function ($department) {
                return [
                    'id' => $department->id,
                    'name' => $department->name
                ];
            });

        // Store in cache
        app('cache')->store('database')->forever($key, $departments);

        // Retrieve from cache to verify
        $cachedDepartments = app('cache')->store('database')->get($key);

        return response()->json([
            'status' => true,
            'message' => 'Department names fetched successfully.',
            'data' => $cachedDepartments ?? $departments, // Fallback to direct data if cache fails
        ]);
    }
}

