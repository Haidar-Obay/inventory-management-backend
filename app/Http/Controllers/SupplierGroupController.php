<?php

namespace App\Http\Controllers;

use App\Models\SupplierGroup;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Imports\DynamicExcelImport;

class SupplierGroupController extends Controller
{
    public function index()
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_supplier_groups";

        $supplierGroups = app('cache')->store('database')->get($key);

        if (!$supplierGroups) {
            $supplierGroups = SupplierGroup::orderBy('name')->get();

            app('cache')->store('database')->forever($key, $supplierGroups);
        }

        return response()->json([
            'status' => true,
            'message' => 'Supplier groups fetched successfully.',
            'data' => $supplierGroups,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string|unique:supplier_groups,code',
            'name' => 'required|string',
            'active' => 'boolean',
        ]);

        $supplierGroup = SupplierGroup::create($validated);

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_supplier_groups");

        return response()->json([
            'status' => true,
            'message' => 'Supplier group created successfully.',
            'data' => $supplierGroup,
        ], 201);
    }

    public function show(SupplierGroup $supplierGroup)
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_supplier_group_{$supplierGroup->id}";

        $cachedSupplierGroup = app('cache')->store('database')->get($key);

        if (!$cachedSupplierGroup) {
            $cachedSupplierGroup = $supplierGroup;

            app('cache')->store('database')->forever($key, $cachedSupplierGroup);
        }

        return response()->json([
            'status' => true,
            'message' => 'Supplier group details fetched successfully.',
            'data' => $cachedSupplierGroup,
        ]);
    }

    public function update(Request $request, SupplierGroup $supplierGroup)
    {
        $validated = $request->validate([
            'code' => [
                'sometimes',
                'string',
                Rule::unique('supplier_groups', 'code')->ignore($supplierGroup->id),
            ],
            'name' => 'sometimes|string',
            'active' => 'sometimes|boolean',
        ]);

        $supplierGroup->update($validated);

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_supplier_groups");
        app('cache')->store('database')->forget("tenant_{$tenantId}_supplier_group_{$supplierGroup->id}");

        return response()->json([
            'status' => true,
            'message' => 'Supplier group updated successfully.',
            'data' => $supplierGroup,
        ]);
    }

    public function destroy(SupplierGroup $supplierGroup)
    {
        // Check if supplier group has suppliers
        if ($supplierGroup->suppliers()->exists()) {
            return response()->json([
                'status' => false,
                'message' => 'Cannot delete supplier group with associated suppliers'
            ], 422);
        }

        $supplierGroup->delete();

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_supplier_groups");
        app('cache')->store('database')->forget("tenant_{$tenantId}_supplier_group_{$supplierGroup->id}");

        return response()->json([
            'status' => true,
            'message' => 'Supplier group deleted successfully.',
        ]);
    }

    public function bulkDelete(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:supplier_groups,id'
        ]);

        $tenantId = tenant('id');
        $skipped = [];
        $deleted = 0;

        // Check for supplier groups with suppliers
        $groupsWithSuppliers = SupplierGroup::whereIn('id', $request->ids)
            ->whereHas('suppliers')
            ->pluck('id');

        if ($groupsWithSuppliers->isNotEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'Some supplier groups have associated suppliers and cannot be deleted',
                'groups_with_suppliers' => $groupsWithSuppliers
            ], 422);
        }

        foreach ($request->ids as $id) {
            try {
                $deleted += SupplierGroup::where('id', $id)->delete();
                app('cache')->store('database')->forget("tenant_{$tenantId}_supplier_group_{$id}");
            } catch (\Illuminate\Database\QueryException $e) {
                $skipped[] = ['id' => $id, 'reason' => $e->getMessage()];
            }
        }

        app('cache')->store('database')->forget("tenant_{$tenantId}_supplier_groups");

        return response()->json([
            'status' => true,
            'message' => 'Bulk delete completed.',
            'deleted_count' => $deleted,
            'skipped' => $skipped,
        ]);
    }

    public function exportExcell()
    {
        $supplierGroups = SupplierGroup::orderBy('name');

        if ($supplierGroups->count() === 0) {
            return response()->json(['message' => 'No supplier groups found.'], 404);
        }

        $columns = ['id', 'code', 'name', 'active'];
        $headings = ['ID', 'Code', 'Name', 'Active'];

        return Excel::download(new Export($supplierGroups, $columns, $headings), 'supplier_groups.xlsx');
    }

    public function exportPdf()
    {
        $supplierGroups = SupplierGroup::select('id', 'code', 'name', 'active')->get();

        if ($supplierGroups->isEmpty()) {
            return response()->json(['message' => 'No supplier groups found.'], 404);
        }

        $title = 'Supplier Group Report';
        $headers = [
            'id' => 'ID',
            'code' => 'Code',
            'name' => 'Name',
            'active' => 'Active',
        ];
        $data = $supplierGroups->toArray();

        $pdf = app(ExportPDF::class)->generatePdf($title, $headers, $data);
        return $pdf->download('SupplierGroups.pdf');
    }

    public function importFromExcel(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv',
        ]);

        try {
            $import = new DynamicExcelImport(
                SupplierGroup::class,
                ['code', 'name'],
                function ($row) {
                    $errors = [];

                    if (empty($row['code'])) {
                        $errors[] = 'Missing code';
                    }
                    if (empty($row['name'])) {
                        $errors[] = 'Missing name';
                    }

                    return $errors;
                },
                function ($row) {
                    return [
                        'code' => $row['code'],
                        'name' => $row['name'],
                        'active' => $row['active'] ?? true,
                    ];
                }
            );

            Excel::import($import, $request->file('file'));

            app('cache')->store('database')->forget('tenant_' . tenant('id') . '_supplier_groups');

            return response()->json([
                'success' => true,
                'rows_imported' => $import->getImportedCount(),
                'rows_skipped_count' => $import->getSkippedCount(),
                'skipped_rows' => $import->getSkippedRows(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Error importing supplier groups: ' . $e->getMessage()
            ], 500);
        }
    }
}
