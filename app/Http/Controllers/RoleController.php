<?php

namespace App\Http\Controllers;

use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Http\Requests\Role\StoreRoleRequest;
use App\Http\Requests\Role\UpdateRoleRequest;
use App\Imports\DynamicExcelImport;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class RoleController extends Controller
{
    /**
     * Check if the authenticated user has the Owner role.
     */
    protected function actingUserIsOwner(Request $request): bool
    {
        $user = $request->user();

        return $user ? ($user->role?->name === 'Owner') : false;
    }

    /**
     * Check if a role is privileged (Owner/Admin).
     */
    protected function roleIsPrivileged(Role $role): bool
    {
        return in_array($role->name, ['Owner', 'Admin']);
    }

    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        try {
            $roles = Role::with(['users', 'creator'])->get();

            $transformedData = $roles->map(function ($role) {
                return [
                    'id' => $role->id,
                    'name' => $role->name,
                    'description' => $role->description,
                    'active' => $role->active,
                    'users_count' => $role->users->count(),
                    'users' => $role->users->map(function ($user) {
                        return [
                            'id' => $user->id,
                            'name' => $user->name,
                            'active' => $user->active,
                        ];
                    }),
                    'created_by' => $role->creator ? [
                        'id' => $role->creator->id,
                        'name' => $role->creator->name,
                    ] : null,
                    'created_at' => $role->created_at,
                    'updated_at' => $role->updated_at,
                ];
            });

            return response()->json([
                'status' => 'success',
                'message' => 'Roles retrieved successfully',
                'data' => $transformedData,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve roles',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreRoleRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();
            $data['created_by'] = Auth::user()->id;

            $nextId = $this->computeNextAvailableId(Role::class, 'id');
            $role = new Role($data);
            $role->id = $nextId;
            $role->save();

            return response()->json([
                'status' => 'success',
                'message' => 'Role created successfully',
                'data' => $role,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create role',
                'error' => $e->getMessage(),
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
                'data' => $transformedData,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve role',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateRoleRequest $request, Role $role): JsonResponse
    {
        try {
            // Guard: Only Owner can modify Owner/Admin roles
            if ($this->roleIsPrivileged($role) && ! $this->actingUserIsOwner($request)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Insufficient permissions to modify privileged roles.',
                ], 403);
            }

            DB::beginTransaction();

            // Update basic role fields
            $validated = $request->validated();
            $role->update(collect($validated)->only(['name', 'description', 'active'])->toArray());

            // Handle optional bulk permission sync
            if (isset($validated['permissions']) && is_array($validated['permissions'])) {
                $sync = $validated['sync'] ?? true;

                // Build sync map: [permission_id => [pivot data]]
                $syncMap = [];
                foreach ($validated['permissions'] as $perm) {
                    $permissionId = $perm['permission_id'];
                    $syncMap[$permissionId] = [
                        'can_view' => (bool) ($perm['can_view'] ?? false),
                        'can_add' => (bool) ($perm['can_add'] ?? false),
                        'can_edit' => (bool) ($perm['can_edit'] ?? false),
                        'can_delete' => (bool) ($perm['can_delete'] ?? false),
                    ];
                }

                if ($sync) {
                    $role->permissions()->sync($syncMap);
                } else {
                    // Attach/update without removing existing others
                    foreach ($syncMap as $permissionId => $pivot) {
                        $role->permissions()->syncWithoutDetaching([$permissionId => $pivot]);
                        // Ensure pivot flags are updated if already attached
                        $role->permissions()->updateExistingPivot($permissionId, $pivot);
                    }
                }
            }

            DB::commit();

            // Return role with permissions and pivot flags
            $role->load(['permissions' => function ($q) {
                $q->select('permissions.id', 'permissions.resource_key', 'permissions.resource_label', 'permissions.created_at', 'permissions.updated_at');
            }]);

            $transformed = [
                'id' => $role->id,
                'name' => $role->name,
                'description' => $role->description,
                'active' => $role->active,
                'permissions' => $role->permissions->map(function ($perm) {
                    return [
                        'permission_id' => $perm->id,
                        'resource_key' => $perm->resource_key,
                        'resource_label' => $perm->resource_label,
                        'can_view' => (bool) $perm->pivot->can_view,
                        'can_add' => (bool) $perm->pivot->can_add,
                        'can_edit' => (bool) $perm->pivot->can_edit,
                        'can_delete' => (bool) $perm->pivot->can_delete,
                    ];
                })->values(),
                'updated_at' => $role->updated_at,
            ];

            return response()->json([
                'status' => 'success',
                'message' => 'Role updated successfully',
                'data' => $transformed,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update role',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Role $role): JsonResponse
    {
        try {
            // Guard: Only Owner can delete Owner/Admin roles
            if ($this->roleIsPrivileged($role) && request()->user()?->role?->name !== 'Owner') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Insufficient permissions to delete privileged roles.',
                ], 403);
            }
            // Prevent deleting protected roles
            if (in_array($role->name, ['Owner', 'Admin'])) {
                return response()->json([
                    'status' => 'error',
                    'message' => "Cannot delete protected role '{$role->name}'.",
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
                'message' => 'Role deleted successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete role',
                'error' => $e->getMessage(),
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
                'data' => $roles,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve active roles',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Toggle role active status.
     */
    public function toggleStatus(Role $role): JsonResponse
    {
        try {
            // Guard: Only Owner can toggle Owner/Admin roles
            if ($this->roleIsPrivileged($role) && request()->user()?->role?->name !== 'Owner') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Insufficient permissions to modify privileged roles.',
                ], 403);
            }
            $role->update(['active' => ! $role->active]);

            return response()->json([
                'status' => 'success',
                'message' => 'Role status updated successfully',
                'data' => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'active' => $role->active,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update role status',
                'error' => $e->getMessage(),
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
            $columns = ['id', 'name', 'description', 'active',
                'created_at',
                'updated_at'];
            $headings = ['ID', 'Name', 'Description', 'Active',
                'Created At', 'Updated At'];

            return Excel::download(new Export($roles, $columns, $headings), 'roles.xlsx');
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to export roles',
                'error' => $e->getMessage(),
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
            $headers = ['id' => 'Role ID', 'name' => 'Role Name', 'description' => 'Description', 'active' => 'Active Status', 'created_at' => 'Created At', 'updated_at' => 'Updated At', 'created_at' => 'Created At', 'updated_at' => 'Updated At'];
            $data = $roles->toArray();

            $pdf = app(ExportPDF::class)->generatePdf($title, $headers, $data);

            return $pdf->download('Roles.pdf');
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to export roles to PDF',
                'error' => $e->getMessage(),
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
                Role::truncate();
            }

            // If type is 'mapping', use provided mapping, else use default
            $mapping = $request->input('mapping');

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
                'error' => $e->getMessage(),
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
                'role_ids.*' => 'exists:roles,id',
            ]);

            $roleIds = $request->input('role_ids');
            $deletedCount = 0;
            $errors = [];
            $isOwner = $request->user() ? ($request->user()->role?->name === 'Owner') : false;

            foreach ($roleIds as $roleId) {
                $role = Role::find($roleId);

                if (! $role) {
                    $errors[] = "Role with ID {$roleId} not found.";

                    continue;
                }

                // Prevent deleting protected roles for non-owners
                if (in_array($role->name, ['Owner', 'Admin']) && ! $isOwner) {
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
                ],
            ];

            if (! empty($errors)) {
                $response['warnings'] = $errors;
            }

            return response()->json($response);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to bulk delete roles',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
