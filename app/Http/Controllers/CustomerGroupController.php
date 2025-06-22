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
        $key = "tenant_{$tenantId}_customer_groups";

        $customerGroups = app('cache')->store('database')->get($key);

        if (!$customerGroups) {
            $customerGroups = CustomerGroup::all();

            app('cache')->store('database')->forever($key, $customerGroups);
        }

        return response()->json([
            'status' => true,
            'message' => 'Customer groups fetched successfully.',
            'data' => $customerGroups,
        ]);
    }

    public function store(Request $request)
    {
        $tenantId = tenant('id');
        $customerGroup = CustomerGroup::create($request->all());
        app('cache')->store('database')->forget("tenant_{$tenantId}_customer_groups");
        return response()->json([
            'status' => true,
            'message' => 'Customer group created successfully.',
            'data' => $customerGroup,
        ], 201);
    }

    public function show(CustomerGroup $customerGroup)
    {
        return response()->json($customerGroup->load('customers'));
    }

    public function update(Request $request, CustomerGroup $customerGroup)
    {
        $tenantId = tenant('id');
        $customerGroup->update($request->all());
        app('cache')->store('database')->forget("tenant_{$tenantId}_customer_groups");

        return response()->json([
            'status' => true,
            'message' => 'Customer group updated successfully.',
            'data' => $customerGroup,
        ]);
    }

    public function destroy(CustomerGroup $customerGroup)
    {
        // Check if customer group has associated customers
        if ($customerGroup->customers()->exists()) {
            return response()->json([
                'status' => false,
                'message' => 'Cannot delete customer group. There are customers associated with this group. Please reassign or delete the customers first.',
            ], 422);
        }

        $tenantId = tenant('id');
        $customerGroup->delete();
        app('cache')->store('database')->forget("tenant_{$tenantId}_customer_groups");

        return response()->json([
            'status' => true,
            'message' => 'Customer group deleted successfully.',
        ]);
    }

    public function bulkDelete(Request $request)
    {
        $tenantId = tenant('id');
        $groupsWithCustomers = CustomerGroup::whereIn('id', $request->ids)
            ->whereHas('customers')
            ->pluck('id');

        if ($groupsWithCustomers->isNotEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'Some customer groups have associated customers and cannot be deleted',
                'groups_with_customers' => $groupsWithCustomers
            ], 400);
        }

        CustomerGroup::whereIn('id', $request->ids)->delete();
        app('cache')->store('database')->forget("tenant_{$tenantId}_customer_groups");

        return response()->json([
            'status' => true,
            'message' => 'Customer groups deleted successfully.',
        ]);
    }

    public function exportExcell()
    {
        $customerGroups = CustomerGroup::orderBy('name');
        $collection = $customerGroups->get();

        if ($collection->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'No customer groups to export',
            ], 404);
        }

        $columns = ['id', 'code', 'name', 'is_inactive'];
        $headings = ['ID', 'Code', 'Name', 'Is Inactive'];

        $fileName = 'customer_groups_' . date('Y-m-d_H-i-s') . '.xlsx';
        return Excel::download(new Export($customerGroups, $columns, $headings), $fileName);
    }

    public function exportPdf()
    {
        $customerGroups = CustomerGroup::select('id', 'code', 'name', 'is_inactive')->get();

        if ($customerGroups->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'No customer groups to export',
            ], 404);
        }

        $fileName = 'customer_groups_' . date('Y-m-d_H-i-s') . '.pdf';
        return Excel::download(new ExportPDF($customerGroups), $fileName);
    }

    public function importFromExcel(Request $request)
    {
        $tenantId = tenant('id');
        $request->validate([
            'file' => 'required|mimes:xlsx,xls',
        ]);

        try {
            $import = new DynamicExcelImport(CustomerGroup::class);
            Excel::import($import, $request->file('file'));

            app('cache')->store('database')->forget("tenant_{$tenantId}_customer_groups");

            return response()->json([
                'status' => true,
                'message' => 'Customer groups imported successfully.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Error importing customer groups: ' . $e->getMessage(),
            ], 500);
        }
    }
}
