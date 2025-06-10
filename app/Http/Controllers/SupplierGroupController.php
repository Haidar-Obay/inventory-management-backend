<?php

namespace App\Http\Controllers;

use App\Models\SupplierGroup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Imports\DynamicExcelImport;

class SupplierGroupController extends Controller
{
    public function index()
    {
        $tenantId = tenant('id');
        $cacheKey = "supplier_groups_{$tenantId}";

        return Cache::remember($cacheKey, 3600, function () {
            return SupplierGroup::all();
        });
    }

    public function store(Request $request)
    {
        $request->validate([
            'code' => 'required|string|unique:supplier_groups,code',
            'name' => 'required|string',
            'is_inactive' => 'boolean',
        ]);

        $supplierGroup = SupplierGroup::create($request->all());
        Cache::forget("supplier_groups_" . tenant('id'));

        return response()->json($supplierGroup, 201);
    }

    public function show(SupplierGroup $supplierGroup)
    {
        $tenantId = tenant('id');
        $cacheKey = "supplier_group_{$supplierGroup->id}_{$tenantId}";

        return Cache::remember($cacheKey, 3600, function () use ($supplierGroup) {
            return $supplierGroup->load('suppliers');
        });
    }

    public function update(Request $request, SupplierGroup $supplierGroup)
    {
        $request->validate([
            'code' => 'required|string|unique:supplier_groups,code,' . $supplierGroup->id,
            'name' => 'required|string',
            'is_inactive' => 'boolean',
        ]);

        $supplierGroup->update($request->all());
        Cache::forget("supplier_groups_" . tenant('id'));
        Cache::forget("supplier_group_{$supplierGroup->id}_" . tenant('id'));

        return response()->json($supplierGroup);
    }

    public function destroy(SupplierGroup $supplierGroup)
    {
        // Check if supplier group has suppliers
        if ($supplierGroup->suppliers()->exists()) {
            return response()->json(['message' => 'Cannot delete supplier group with associated suppliers'], 422);
        }

        $supplierGroup->delete();
        Cache::forget("supplier_groups_" . tenant('id'));
        Cache::forget("supplier_group_{$supplierGroup->id}_" . tenant('id'));

        return response()->json(null, 204);
    }

    public function bulkDelete(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:supplier_groups,id'
        ]);

        // Check for supplier groups with suppliers
        $groupsWithSuppliers = SupplierGroup::whereIn('id', $request->ids)
            ->whereHas('suppliers')
            ->pluck('id');

        if ($groupsWithSuppliers->isNotEmpty()) {
            return response()->json([
                'message' => 'Some supplier groups have associated suppliers and cannot be deleted',
                'groups_with_suppliers' => $groupsWithSuppliers
            ], 422);
        }

        SupplierGroup::whereIn('id', $request->ids)->delete();
        Cache::forget("supplier_groups_" . tenant('id'));

        return response()->json(['message' => 'Supplier groups deleted successfully']);
    }

    public function exportExcell()
    {
        $supplierGroups = SupplierGroup::all();

        if ($supplierGroups->isEmpty()) {
            return response()->json(['message' => 'No supplier groups to export'], 404);
        }

        $fileName = 'supplier_groups_' . date('Y-m-d_H-i-s') . '.xlsx';
        return Excel::download(new Export($supplierGroups), $fileName);
    }

    public function exportPdf()
    {
        $supplierGroups = SupplierGroup::all();

        if ($supplierGroups->isEmpty()) {
            return response()->json(['message' => 'No supplier groups to export'], 404);
        }

        $fileName = 'supplier_groups_' . date('Y-m-d_H-i-s') . '.pdf';
        return Excel::download(new ExportPDF($supplierGroups), $fileName);
    }

    public function importFromExcel(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:xlsx,xls',
        ]);

        try {
            $import = new DynamicExcelImport(SupplierGroup::class);
            Excel::import($import, $request->file('file'));

            Cache::forget("supplier_groups_" . tenant('id'));

            return response()->json(['message' => 'Supplier groups imported successfully']);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error importing supplier groups: ' . $e->getMessage()], 500);
        }
    }
}
