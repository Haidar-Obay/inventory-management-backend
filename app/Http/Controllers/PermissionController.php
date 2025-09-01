<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use App\Http\Requests\Permission\StorePermissionRequest;
use App\Http\Requests\Permission\UpdatePermissionRequest;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Imports\DynamicExcelImport;

class PermissionController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = Permission::with('roles');

            // Search functionality
            if ($request->has('search') && !empty($request->search)) {
                $query->search($request->search);
            }

            $permissions = $query->get();

            $transformedData = $permissions->map(function ($permission) {
                return [
                    'id' => $permission->id,
                    'resource_key' => $permission->resource_key,
                    'resource_label' => $permission->resource_label,
                    'roles_count' => $permission->roles->count(),
                    'created_at' => $permission->created_at,
                    'updated_at' => $permission->updated_at,
                ];
            });

            return response()->json([
                'status' => 'success',
                'message' => 'Permissions retrieved successfully',
                'data' => $transformedData
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve permissions',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StorePermissionRequest $request): JsonResponse
    {
        try {
            $permission = Permission::create($request->validated());

            return response()->json([
                'status' => 'success',
                'message' => 'Permission created successfully',
                'data' => $permission
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create permission',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Permission $permission): JsonResponse
    {
        try {
            $permission->load('roles');

            $transformedData = [
                'id' => $permission->id,
                'resource_key' => $permission->resource_key,
                'resource_label' => $permission->resource_label,
                'roles' => $permission->roles->map(function ($role) {
                    return [
                        'id' => $role->id,
                        'name' => $role->name,
                        'description' => $role->description,
                    ];
                }),
                'created_at' => $permission->created_at,
                'updated_at' => $permission->updated_at,
            ];

            return response()->json([
                'status' => 'success',
                'message' => 'Permission retrieved successfully',
                'data' => $transformedData
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve permission',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdatePermissionRequest $request, Permission $permission): JsonResponse
    {
        try {
            $permission->update($request->validated());

            return response()->json([
                'status' => 'success',
                'message' => 'Permission updated successfully',
                'data' => $permission
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update permission',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Permission $permission): JsonResponse
    {
        try {
            // Check if permission has roles assigned
            if ($permission->roles()->count() > 0) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Cannot delete permission. It has roles assigned to it.',
                ], 422);
            }

            $permission->delete();

            return response()->json([
                'status' => 'success',
                'message' => 'Permission deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete permission',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Export permissions to Excel.
     */
    public function exportExcell()
    {
        try {
            $permissions = Permission::with('roles');
            $columns = ['id', 'resource_key', 'resource_label'];
            $headings = ['ID', 'Resource Key', 'Resource Label'];

            return Excel::download(new Export($permissions, $columns, $headings), 'permissions.xlsx');
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to export permissions',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Export permissions to PDF.
     */
    public function exportPdf()
    {
        try {
            $permissions = Permission::select('id', 'resource_key', 'resource_label')->get();

            if ($permissions->isEmpty()) {
                return response()->json(['message' => 'No permissions found.'], 404);
            }

            $title = 'Permissions Report';
            $headers = [
                'id' => 'Permission ID',
                'resource_key' => 'Resource Key',
                'resource_label' => 'Resource Label'
            ];
            $data = $permissions->toArray();

            $pdf = app(ExportPDF::class)->generatePdf($title, $headers, $data);
            return $pdf->download('Permissions.pdf');
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to export permissions to PDF',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Import permissions from Excel.
     */
    public function importFromExcel(Request $request)
    {
        try {
            $request->validate([
                'file' => 'required|file|mimes:xlsx,xls,csv',
            ]);

            $import = new DynamicExcelImport(
                Permission::class,
                ['resource_key'],
                function ($row) {
                    $errors = [];

                    if (empty($row['resource_key'])) {
                        $errors[] = 'Missing resource key';
                    }

                    if (empty($row['resource_label'])) {
                        $errors[] = 'Missing resource label';
                    }

                    return $errors;
                },
                function ($row) {
                    return [
                        'resource_key' => $row['resource_key'],
                        'resource_label' => $row['resource_label'],
                    ];
                }
            );

            Excel::import($import, $request->file('file'));

            return response()->json([
                'success' => true,
                'rows_imported' => $import->getImportedCount(),
                'rows_skipped_count' => $import->getSkippedCount(),
                'skipped_rows' => $import->getSkippedRows(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to import permissions',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bulk delete permissions.
     */
    public function bulkDelete(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'permission_ids' => 'required|array',
                'permission_ids.*' => 'exists:permissions,id'
            ]);

            $permissionIds = $request->input('permission_ids');
            $deletedCount = 0;
            $errors = [];

            foreach ($permissionIds as $permissionId) {
                $permission = Permission::find($permissionId);
                
                if ($permission->roles()->count() > 0) {
                    $errors[] = "Cannot delete permission '{$permission->resource_key}' - it has roles assigned to it.";
                    continue;
                }

                $permission->delete();
                $deletedCount++;
            }

            $response = [
                'status' => 'success',
                'message' => "Successfully deleted {$deletedCount} permissions",
                'data' => [
                    'deleted_count' => $deletedCount,
                    'total_requested' => count($permissionIds),
                ]
            ];

            if (!empty($errors)) {
                $response['warnings'] = $errors;
            }

            return response()->json($response);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to bulk delete permissions',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
