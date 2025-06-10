<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;
use App\Models\CustomerGroup;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Imports\DynamicExcelImport;
use Illuminate\Support\Facades\Cache;

class CustomerGroupController extends Controller
{
    public function index()
    {
        $tenantId = tenant('id');
        $cacheKey = "customer_groups_{$tenantId}";

        return Cache::remember($cacheKey, 3600, function () {
            return CustomerGroup::all();
        });
    }

    public function store(Request $request)
    {
        $request->validate([
            'code' => 'required|string|unique:customer_groups,code',
            'name' => 'required|string',
            'is_inactive' => 'boolean',
        ]);

        $customerGroup = CustomerGroup::create($request->all());
        Cache::forget("customer_groups_" . tenant('id'));

        return response()->json($customerGroup, 201);
    }

    public function show(CustomerGroup $customerGroup)
    {
        $tenantId = tenant('id');
        $cacheKey = "customer_group_{$customerGroup->id}_{$tenantId}";

        return Cache::remember($cacheKey, 3600, function () use ($customerGroup) {
            return $customerGroup->load('customers');
        });
    }

    public function update(Request $request, CustomerGroup $customerGroup)
    {
        $request->validate([
            'code' => 'required|string|unique:customer_groups,code,' . $customerGroup->id,
            'name' => 'required|string',
            'is_inactive' => 'boolean',
        ]);

        $customerGroup->update($request->all());
        Cache::forget("customer_groups_" . tenant('id'));
        Cache::forget("customer_group_{$customerGroup->id}_" . tenant('id'));

        return response()->json($customerGroup);
    }

    public function destroy(CustomerGroup $customerGroup)
    {
        // Check if customer group has customers
        if ($customerGroup->customers()->exists()) {
            return response()->json(['message' => 'Cannot delete customer group with associated customers'], 422);
        }

        $customerGroup->delete();
        Cache::forget("customer_groups_" . tenant('id'));
        Cache::forget("customer_group_{$customerGroup->id}_" . tenant('id'));

        return response()->json(null, 204);
    }

    public function bulkDelete(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:customer_groups,id'
        ]);

        // Check for customer groups with customers
        $groupsWithCustomers = CustomerGroup::whereIn('id', $request->ids)
            ->whereHas('customers')
            ->pluck('id');

        if ($groupsWithCustomers->isNotEmpty()) {
            return response()->json([
                'message' => 'Some customer groups have associated customers and cannot be deleted',
                'groups_with_customers' => $groupsWithCustomers
            ], 422);
        }

        CustomerGroup::whereIn('id', $request->ids)->delete();
        Cache::forget("customer_groups_" . tenant('id'));

        return response()->json(['message' => 'Customer groups deleted successfully']);
    }

    public function exportExcell()
    {
        $customerGroups = CustomerGroup::all();

        if ($customerGroups->isEmpty()) {
            return response()->json(['message' => 'No customer groups to export'], 404);
        }

        $fileName = 'customer_groups_' . date('Y-m-d_H-i-s') . '.xlsx';
        return Excel::download(new Export($customerGroups), $fileName);
    }

    public function exportPdf()
    {
        $customerGroups = CustomerGroup::all();

        if ($customerGroups->isEmpty()) {
            return response()->json(['message' => 'No customer groups to export'], 404);
        }

        $fileName = 'customer_groups_' . date('Y-m-d_H-i-s') . '.pdf';
        return Excel::download(new ExportPDF($customerGroups), $fileName);
    }

    public function importFromExcel(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:xlsx,xls',
        ]);

        try {
            $import = new DynamicExcelImport(CustomerGroup::class);
            Excel::import($import, $request->file('file'));

            Cache::forget("customer_groups_" . tenant('id'));

            return response()->json(['message' => 'Customer groups imported successfully']);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error importing customer groups: ' . $e->getMessage()], 500);
        }
    }
}
