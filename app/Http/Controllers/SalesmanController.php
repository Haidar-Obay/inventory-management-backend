<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Salesman;
use Illuminate\Http\Request;
use App\Http\Requests\Salesman\StoreSalesmanRequest;
use App\Http\Requests\Salesman\UpdateSalesmanRequest;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Imports\DynamicExcelImport;

class SalesmanController extends Controller
{
    public function index()
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_salesmen";

        $salesmen = app('cache')->store('database')->get($key);

        if (!$salesmen) {
            $salesmen = Salesman::withCount('customers')->get();
            app('cache')->store('database')->forever($key, $salesmen);
        }

        return response()->json([
            'status' => true,
            'message' => 'Salesmen fetched successfully.',
            'data' => $salesmen,
        ]);
    }

    public function show(Salesman $salesman)
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_salesman_{$salesman->id}";

        $cached = app('cache')->store('database')->get($key);

        if (!$cached) {
            $salesman->loadCount('customers');
            app('cache')->store('database')->forever($key, $salesman);
        } else {
            $salesman = $cached;
        }

        return response()->json([
            'status' => true,
            'message' => 'Salesman details fetched successfully.',
            'data' => $salesman,
        ]);
    }

    public function store(StoreSalesmanRequest $request)
    {
        $salesman = Salesman::create($request->validated());

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_salesmen");

        return response()->json([
            'status' => true,
            'message' => 'Salesman created successfully.',
            'data' => $salesman,
        ], 201);
    }

    public function update(UpdateSalesmanRequest $request, Salesman $salesman)
    {
        $salesman->update($request->validated());

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_salesmen");
        app('cache')->store('database')->forget("tenant_{$tenantId}_salesman_{$salesman->id}");

        return response()->json([
            'status' => true,
            'message' => 'Salesman updated successfully.',
            'data' => $salesman,
        ]);
    }

    public function destroy(Salesman $salesman)
    {
        $salesman->delete();

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_salesmen");
        app('cache')->store('database')->forget("tenant_{$tenantId}_salesman_{$salesman->id}");

        return response()->json([
            'status' => true,
            'message' => 'Salesman deleted successfully.',
        ]);
    }

    public function bulkDelete(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:salesmen,id',
        ]);

        $skipped = [];
        $deleted = 0;
        $tenantId = tenant('id');

        foreach ($request->ids as $id) {
            try {
                $deleted += Salesman::where('id', $id)->delete();
                app('cache')->store('database')->forget("tenant_{$tenantId}_salesman_{$id}");
            } catch (\Illuminate\Database\QueryException $e) {
                $skipped[] = ['id' => $id, 'reason' => $e->getMessage()];
            }
        }

        app('cache')->store('database')->forget("tenant_{$tenantId}_salesmen");

        return response()->json([
            'message' => 'Bulk delete completed.',
            'deleted_count' => $deleted,
            'skipped' => $skipped,
        ]);
    }

    public function exportExcell()
    {
        $salesmenQuery = Salesman::query();

        if (!$salesmenQuery->exists()) {
            return response()->json(['message' => 'No Salesman found.'], 404);
        }

        $transformedQuery = Salesman::query()
            ->selectRaw("id, name, email, phone1, phone2, address, fix_commission, CASE WHEN is_inactive THEN 'Yes' ELSE 'No' END as is_inactive");

        $columns = ['id', 'name', 'email', 'phone1', 'phone2', 'address', 'fix_commission', 'is_inactive'];
        $headings = ['ID', 'Name', 'Email', 'Phone 1', 'Phone 2', 'Address', 'Fix Commission', 'Is Inactive'];

        return Excel::download(new Export($transformedQuery, $columns, $headings), 'Salesman.xlsx');
    }

    public function exportPdf(ExportPDF $pdfService)
    {
        $salesmen = Salesman::select('id', 'name', 'email', 'phone1', 'phone2', 'address', 'fix_commission', 'is_inactive')->get();

        if ($salesmen->isEmpty()) {
            return response()->json(['message' => 'No salesmen found.'], 404);
        }

        $title = 'Salesmen Group Report';
        $headers = [
            'id' => 'ID',
            'name' => 'Name',
            'email' => 'Email',
            'phone1' => 'Phone 1',
            'phone2' => 'Phone 2',
            'address' => 'Address',
            'fix_commission' => 'Fix Commission',
            'is_inactive' => 'Is Inactive',
        ];
        $data = $salesmen->toArray();

        $pdf = $pdfService->generatePdf($title, $headers, $data);
        return $pdf->download('Salesmen.pdf');
    }

    public function importFromExcel(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv',
        ]);

        $import = new DynamicExcelImport(
            Salesman::class,
            ['name', 'email', 'phone1', 'phone2', 'address', 'fix_commission', 'is_inactive'],
            function ($row) {
                $errors = [];

                if (empty($row['name'])) {
                    $errors[] = 'Missing name';
                } elseif (preg_match('/\d/', $row['name'])) {
                    $errors[] = 'Name cannot contain numbers';
                }

                if (empty($row['email']) || !filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
                    $errors[] = 'Invalid or missing email';
                }

                foreach (['phone1', 'phone2'] as $phoneField) {
                    if (empty($row[$phoneField]) || !ctype_digit(strval($row[$phoneField]))) {
                        $errors[] = "Invalid or missing $phoneField";
                    }
                }

                if (empty($row['address'])) {
                    $errors[] = 'Missing address';
                }

                if (!isset($row['fix_commission']) || !is_numeric($row['fix_commission'])) {
                    $errors[] = 'Missing or invalid fix_commission';
                }

                if (!isset($row['is_inactive'])) {
                    $errors[] = 'Missing is_inactive';
                }

                return $errors;
            },
            function ($row) {
                return [
                    'name' => $row['name'],
                    'email' => $row['email'],
                    'phone1' => $row['phone1'],
                    'phone2' => $row['phone2'],
                    'address' => $row['address'],
                    'fix_commission' => floatval($row['fix_commission']),
                    'is_inactive' => boolval($row['is_inactive']),
                ];
            }
        );

        Excel::import($import, $request->file('file'));

        app('cache')->store('database')->forget("tenant_" . tenant('id') . "_salesmen");

        return response()->json([
            'success' => true,
            'rows_imported' => $import->getImportedCount(),
            'rows_skipped_count' => $import->getSkippedCount(),
            'skipped_rows' => $import->getSkippedRows(),
        ]);
    }
}
