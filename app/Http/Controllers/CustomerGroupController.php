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

class CustomerGroupController extends Controller
{
    public function index()
    {
        $groups = CustomerGroup::withCount('customers')
            ->orderBy('name')
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'Customer groups fetched successfully.',
            'data' => $groups,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:customer_groups,name',
        ]);

        $group = CustomerGroup::create($validated);

        return response()->json([
            'status' => true,
            'message' => 'Customer group created successfully.',
            'data' => $group,
        ], 201);
    }

    public function show(CustomerGroup $customerGroup)
    {
        $customerGroup->loadCount('customers');

        return response()->json([
            'status' => true,
            'message' => 'Customer group details fetched successfully.',
            'data' => $customerGroup,
        ]);
    }

    public function update(Request $request, CustomerGroup $customerGroup)
    {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('customer_groups')->ignore($customerGroup->id),
            ],
        ]);

        $customerGroup->update($validated);

        return response()->json([
            'status' => true,
            'message' => 'Customer group updated successfully.',
            'data' => $customerGroup,
        ]);
    }

    public function destroy(CustomerGroup $customerGroup)
    {
        $customerGroup->delete();

        return response()->json([
            'status' => true,
            'message' => 'Customer group deleted successfully.',
        ]);
    }

    public function bulkDelete(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:customer_groups,id',
        ]);

        $skipped = [];
        $deleted = 0;

        foreach ($request->ids as $id) {
            try {
                $deleted += CustomerGroup::where('id', $id)->delete();
            } catch (\Illuminate\Database\QueryException $e) {
                $skipped[] = ['id' => $id, 'reason' => $e->getMessage()];
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
        $CustomerGroup = CustomerGroup::query();
        $collection = $CustomerGroup->get();
        if ($collection->isEmpty()) {
            return response()->json(['message' => 'No Customer_Groups found.'], 404);
        }
        $columns = ['id', 'name'];
        $headings = ['ID', 'Name'];
        return Excel::download(new Export($CustomerGroup, $columns, $headings), 'CustomerGroups.xlsx');
    }

    public function exportPdf(ExportPDF $pdfService)
    {
        $customerGroups = CustomerGroup::select(
            'id',
            'name'
        )->get();

        if ($customerGroups->isEmpty()) {
            return response()->json(['message' => 'No customer groups found.'], 404);
        }

        $title = 'Customer Group Report';
        $headers = [
            'id' => 'Customer Group ID',
            'name' => 'Customer Group Name'
        ];
        $data = $customerGroups->toArray();

        $pdf = $pdfService->generatePdf($title, $headers, $data);
        return $pdf->download('CustomerGroups.pdf');
    }

    public function importFromExcel(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv',
        ]);

        $import = new DynamicExcelImport(
            CustomerGroup::class,
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
    }


}
