<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Http\Requests\Role\StoreRoleRequest;
use App\Http\Requests\Role\UpdateRoleRequest;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Imports\DynamicExcelImport;

class RoleController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        try {
            $roles = Role::with('users')->get();

            $transformedData = $roles->map(function ($role) {
                return [
                    'id' => $role->id,
                    'name' => $role->name,
                    'description' => $role->description,
                    'active' => $role->active,
                    'users_count' => $role->users->count(),
                    'created_at' => $role->created_at,
                    'updated_at' => $role->updated_at,
                ];
            });

            return response()->json([
                'status' => 'success',
                'message' => 'Roles retrieved successfully',
                'data' => $transformedData
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve roles',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreRoleRequest $request): JsonResponse
    {
        try {
            $role = Role::create($request->validated());

            return response()->json([
                'status' => 'success',
                'message' => 'Role created successfully',
                'data' => $role
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create role',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Role $role): JsonResponse
    {
        try {
            $role->load('users');

            $transformedData = [
                'id' => $role->id,
                'name' => $role->name,
                'description' => $role->description,
                'active' => $role->active,
                'users' => $role->users->map(function ($user) {
                    return [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                    ];
                }),
                'created_at' => $role->created_at,
                'updated_at' => $role->updated_at,
            ];

            return response()->json([
                'status' => 'success',
                'message' => 'Role retrieved successfully',
                'data' => $transformedData
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve role',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateRoleRequest $request, Role $role): JsonResponse
    {
        try {
            $role->update($request->validated());

            return response()->json([
                'status' => 'success',
                'message' => 'Role updated successfully',
                'data' => $role
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update role',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Role $role): JsonResponse
    {
        try {
            // Prevent deleting protected roles
            if (in_array($role->name, ['Owner', 'Admin'])) {
                return response()->json([
                    'status' => 'error',
                    'message' => "Cannot delete protected role '{$role->name}'."
                ], 422);
            }

            // Check if role has users assigned
            if ($role->users()->count() > 0) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Cannot delete role. It has users assigned to it.',
                ], 422);
            }

            $role->delete();

            return response()->json([
                'status' => 'success',
                'message' => 'Role deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete role',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get only active roles.
     */
    public function active(): JsonResponse
    {
        try {
            $roles = Role::active()->get(['id', 'name', 'description']);

            return response()->json([
                'status' => 'success',
                'message' => 'Active roles retrieved successfully',
                'data' => $roles
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve active roles',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Toggle role active status.
     */
    public function toggleStatus(Role $role): JsonResponse
    {
        try {
            $role->update(['active' => !$role->active]);

            return response()->json([
                'status' => 'success',
                'message' => 'Role status updated successfully',
                'data' => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'active' => $role->active
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update role status',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Export roles to Excel.
     */
    public function exportExcell()
    {
        try {
            $roles = Role::with('users');
            $columns = ['id', 'name', 'description', 'active'];
            $headings = ['ID', 'Name', 'Description', 'Active'];

            return Excel::download(new Export($roles, $columns, $headings), 'roles.xlsx');
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to export roles',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Export roles to PDF.
     */
    public function exportPdf()
    {
        try {
            $roles = Role::select('id', 'name', 'description', 'active')->get();

            if ($roles->isEmpty()) {
                return response()->json(['message' => 'No roles found.'], 404);
            }

            $title = 'Roles Report';
            $headers = [
                'id' => 'Role ID',
                'name' => 'Role Name',
                'description' => 'Description',
                'active' => 'Active Status'
            ];
            $data = $roles->toArray();

            $pdf = app(ExportPDF::class)->generatePdf($title, $headers, $data);
            return $pdf->download('Roles.pdf');
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to export roles to PDF',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Import roles from Excel.
     */
    public function importFromExcel(Request $request)
    {
        try {
            $request->validate([
                'file' => 'required|file|mimes:xlsx,xls,csv',
            ]);

            $import = new DynamicExcelImport(
                Role::class,
                ['name'],
                function ($row) {
                    $errors = [];

                    if (empty($row['name'])) {
                        $errors[] = 'Missing name';
                    }

                    return $errors;
                },
                function ($row) {
                    return [
                        'name' => $row['name'],
                        'description' => $row['description'] ?? null,
                        'active' => isset($row['active']) ? filter_var($row['active'], FILTER_VALIDATE_BOOLEAN) : true,
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
                'message' => 'Failed to import roles',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bulk delete roles.
     */
    public function bulkDelete(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'role_ids' => 'required|array',
                'role_ids.*' => 'exists:roles,id'
            ]);

            $roleIds = $request->input('role_ids');
            $deletedCount = 0;
            $errors = [];

            foreach ($roleIds as $roleId) {
                $role = Role::find($roleId);
                
                if (!$role) {
                    $errors[] = "Role with ID {$roleId} not found.";
                    continue;
                }
                
                // Prevent deleting protected roles
                if (in_array($role->name, ['Owner', 'Admin'])) {
                    $errors[] = "Cannot delete protected role '{$role->name}'.";
                    continue;
                }
                
                if ($role->users()->count() > 0) {
                    $errors[] = "Cannot delete role '{$role->name}' - it has users assigned to it.";
                    continue;
                }

                $role->delete();
                $deletedCount++;
            }

            $response = [
                'status' => 'success',
                'message' => "Successfully deleted {$deletedCount} roles",
                'data' => [
                    'deleted_count' => $deletedCount,
                    'total_requested' => count($roleIds),
                ]
            ];

            if (!empty($errors)) {
                $response['warnings'] = $errors;
            }

            return response()->json($response);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to bulk delete roles',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
