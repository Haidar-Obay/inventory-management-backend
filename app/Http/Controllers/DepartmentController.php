<?php

namespace App\Http\Controllers;

use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Http\Requests\Department\StoreDepartmentRequest;
use App\Http\Requests\Department\UpdateDepartmentRequest;
use App\Imports\DynamicExcelImport;
use App\Models\Department;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;

class DepartmentController extends Controller
{
    public function index()
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_departments";

        $departments = app('cache')->store('database')->get($key);

        if (! $departments) {
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
        $validated = $request->validated();

        // Check if the parent department is not itself a sub-department
        if (isset($validated['sub_department_of']) && $validated['sub_department_of']) {
            $parentDepartment = Department::find($validated['sub_department_of']);
            if ($parentDepartment && $parentDepartment->sub_department_of) {
                return response()->json([
                    'status' => false,
                    'message' => 'Cannot create sub-department under another sub-department. Only top-level departments can have sub-departments.',
                ], 422);
            }
        }

        $tenantId = tenant('id');
        $department = Department::create($validated);
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
            Log::error('Error fetching department: '.$e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Department not found',
            ], 404);
        }
    }

    public function update(UpdateDepartmentRequest $request, Department $department)
    {
        $validated = $request->validated();

        // Check if the parent department is not itself a sub-department
        if (isset($validated['sub_department_of']) && $validated['sub_department_of']) {
            $parentDepartment = Department::find($validated['sub_department_of']);
            if ($parentDepartment && $parentDepartment->sub_department_of) {
                return response()->json([
                    'status' => false,
                    'message' => 'Cannot assign sub-department under another sub-department. Only top-level departments can have sub-departments.',
                ], 422);
            }
        }

        $tenantId = tenant('id');
        $department->update($validated);
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
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:departments,id',
        ]);

        $ids = $request->input('ids');
        $skipped = [];
        $deleted = 0;

        foreach ($ids as $id) {
            try {
                $department = Department::find($id);

                if (! $department) {
                    $skipped[] = [
                        'id' => $id,
                        'reason' => 'Department not found.',
                    ];

                    continue;
                }

                // Check if department has sub-departments
                if ($department->hasSubDepartments()) {
                    $skipped[] = [
                        'id' => $id,
                        'reason' => 'Cannot delete department. It has sub-departments.',
                    ];

                    continue;
                }

                $department->delete();
                app('cache')->store('database')->forget('departments_'.tenant('id'));
                app('cache')->store('database')->forget("department_{$department->id}_".tenant('id'));
                $deleted++;

            } catch (\Exception $e) {
                Log::error('Error deleting department '.$id.': '.$e->getMessage());
                $skipped[] = [
                    'id' => $id,
                    'reason' => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'message' => 'Bulk delete completed.',
            'deleted_count' => $deleted,
            'skipped' => $skipped,
        ]);
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
                'departments.active',
                'departments.created_at',
                'departments.updated_at',
            ]);

        $collection = $departments->get();

        if ($collection->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'No data to export',
            ]);
        }

        $columns = ['id', 'code', 'name', 'parent_code', 'active', 'created_at', 'updated_at'];
        $headings = ['ID', 'Code', 'Name', 'Parent Department', 'Status', 'Created At', 'Updated At'];

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
                'departments.active',
                'created_at', 'updated_at'])
            ->get();

        if ($departments->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'No data to export',
            ]);
        }

        $title = 'Departments Report';
        $headers = ['id' => 'ID', 'code' => 'Code', 'name' => 'Name', 'parent_code' => 'Parent Department', 'active' => 'Status', 'created_at' => 'Created At', 'updated_at' => 'Updated At'];
        $data = $departments->toArray();

        $pdf = $pdfService->generatePdf($title, $headers, $data);

        return $pdf->download('Departments.pdf');
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
            Department::truncate();
        }

        // If type is 'mapping', use provided mapping, else use default
        $mapping = $request->input('mapping');

        try {
            $import = new DynamicExcelImport(
                Department::class,
                ['code', 'name', 'sub_department_of', 'active'],
                function ($row) {
                    foreach ($row as $k => $v) {
                        if (is_string($v)) {
                            $row[$k] = trim($v);
                        }
                    }
                    $errors = [];
                    if (($row['code'] ?? '') === '') {
                        $errors[] = 'Missing code';
                    }
                    if (($row['name'] ?? '') === '') {
                        $errors[] = 'Missing name';
                    }
                    // Validate parent department code if provided
                    if (! empty($row['sub_department_of'])) {
                        $parentDepartment = Department::whereRaw('LOWER(TRIM(code)) = ?', [mb_strtolower($row['sub_department_of'])])->first();
                        if (! $parentDepartment) {
                            $errors[] = "Parent department with code '{$row['sub_department_of']}' not found";
                        }
                    }

                    return $errors;
                },
                function ($row) {
                    foreach ($row as $k => $v) {
                        if (is_string($v)) {
                            $row[$k] = trim($v);
                        }
                    }
                    $subDepartmentOfId = null;

                    // If sub_department_of is provided, resolve the code to ID
                    if (! empty($row['sub_department_of'])) {
                        $parentDepartment = Department::whereRaw('LOWER(TRIM(code)) = ?', [mb_strtolower($row['sub_department_of'])])->first();
                        if ($parentDepartment) {
                            $subDepartmentOfId = $parentDepartment->id;
                        }
                    }

                    return [
                        'code' => $row['code'] ?? null,
                        'name' => $row['name'] ?? null,
                        'sub_department_of' => $subDepartmentOfId,
                        'active' => boolval($row['active'] ?? true),
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

            app('cache')->store('database')->forget("tenant_{$tenantId}_departments");

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
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Import failed: '.$e->getMessage(),
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
                'sub_department_of' => ['A department cannot be a sub-department of itself'],
            ]);
        }

        try {
            $parent = Department::find($parentId);
            if ($parent && $parent->isSubDepartment()) {
                $ancestors = $this->getAncestors($parent);
                if (in_array($currentId, $ancestors)) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'sub_department_of' => ['Circular reference detected in department hierarchy'],
                    ]);
                }
            }
        } catch (\Exception $e) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'sub_department_of' => ['An error occurred while validating the department hierarchy'],
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
        $departments = Department::whereNull('sub_department_of')
            ->select('id', 'name', 'created_at', 'updated_at')
            ->orderBy('name')
            ->get()
            ->map(function ($department) {
                return [
                    'id' => $department->id,
                    'name' => $department->name,
                    'created_at' => $department->created_at,
                    'updated_at' => $department->updated_at,
                ];
            });

        return response()->json([
            'status' => true,
            'message' => 'Department names fetched successfully.',
            'data' => $departments,
        ]);
    }
}
