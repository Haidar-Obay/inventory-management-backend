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
            $salesmen = Salesman::withCount('customers')->orderBy('name')->get();
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

        $cachedSalesman = app('cache')->store('database')->get($key);

        if (!$cachedSalesman) {
            $cachedSalesman = $salesman->loadCount('customers');
            app('cache')->store('database')->forever($key, $cachedSalesman);
        }

        return response()->json([
            'status' => true,
            'message' => 'Salesman details fetched successfully.',
            'data' => $cachedSalesman,
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
        // Check if salesman has associated customers
        if ($salesman->customers()->exists()) {
            return response()->json([
                'status' => false,
                'message' => 'Cannot delete salesman. There are customers associated with this salesman. Please reassign or delete the customers first.',
            ], 422);
        }

        $tenantId = tenant('id');
        $salesman->delete();
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

        $tenantId = tenant('id');
        $skipped = [];
        $deleted = 0;

        foreach ($request->ids as $id) {
            try {
                $salesman = Salesman::find($id);
                if ($salesman->customers()->exists()) {
                    $skipped[] = ['id' => $id, 'reason' => 'Salesman has associated customers'];
                    continue;
                }
                $deleted += $salesman->delete();
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
        $salesmen = Salesman::orderBy('name');
        $collection = $salesmen->get();

        if ($collection->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'No salesmen to export',
            ], 404);
        }

        $columns = ['id', 'code', 'name', 'email', 'phone1', 'phone2', 'address', 'is_manager', 'is_supervisor', 'is_collector', 'fix_commission', 'commission_percent', 'commission_by_item', 'commission_by_turnover', 'active'];
        $headings = ['ID', 'Code', 'Name', 'Email', 'Phone 1', 'Phone 2', 'Address', 'Is Manager', 'Is Supervisor', 'Is Collector', 'Fix Commission', 'Commission %', 'Commission by Item', 'Commission by Turnover', 'Active'];

        $fileName = 'salesmen_' . date('Y-m-d_H-i-s') . '.xlsx';
        return Excel::download(new Export($salesmen, $columns, $headings), $fileName);
    }

    public function exportPdf(ExportPDF $pdfService)
    {
        $salesmen = Salesman::select('id', 'code', 'name', 'email', 'phone1', 'phone2', 'address', 'is_manager', 'is_supervisor', 'is_collector', 'fix_commission', 'commission_percent', 'commission_by_item', 'commission_by_turnover', 'active')->get();

        if ($salesmen->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'No salesmen to export',
            ], 404);
        }

        $title = 'Salesmen Report';
        $headers = [
            'id' => 'ID',
            'code' => 'Code',
            'name' => 'Name',
            'email' => 'Email',
            'phone1' => 'Phone 1',
            'phone2' => 'Phone 2',
            'address' => 'Address',
            'is_manager' => 'Is Manager',
            'is_supervisor' => 'Is Supervisor',
            'is_collector' => 'Is Collector',
            'fix_commission' => 'Fix Commission',
            'commission_percent' => 'Commission %',
            'commission_by_item' => 'Commission by Item',
            'commission_by_turnover' => 'Commission by Turnover',
            'active' => 'Active'
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
            ['code', 'name', 'email', 'phone1', 'phone2', 'address', 'is_manager', 'is_supervisor', 'is_collector', 'fix_commission', 'commission_percent', 'commission_by_item', 'commission_by_turnover', 'active'],
            function ($row) {
                $errors = [];

                if (empty($row['code'])) {
                    $errors[] = 'Missing code';
                }
                if (empty($row['name'])) {
                    $errors[] = 'Missing name';
                }
                if (!empty($row['email']) && !filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
                    $errors[] = 'Invalid email format';
                }

                return $errors;
            },
            function ($row) {
                return [
                    'code' => $row['code'],
                    'name' => $row['name'],
                    'email' => $row['email'] ?? null,
                    'phone1' => $row['phone1'] ?? null,
                    'phone2' => $row['phone2'] ?? null,
                    'address' => $row['address'] ?? null,
                    'is_manager' => boolval($row['is_manager'] ?? false),
                    'is_supervisor' => boolval($row['is_supervisor'] ?? false),
                    'is_collector' => boolval($row['is_collector'] ?? false),
                    'fix_commission' => floatval($row['fix_commission'] ?? 0),
                    'commission_percent' => floatval($row['commission_percent'] ?? 0),
                    'commission_by_item' => floatval($row['commission_by_item'] ?? 0),
                    'commission_by_turnover' => floatval($row['commission_by_turnover'] ?? 0),
                    'active' => boolval($row['active'] ?? true),
                ];
            }
        );

        Excel::import($import, $request->file('file'));

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_salesmen");

        return response()->json([
            'success' => true,
            'rows_imported' => $import->getImportedCount(),
            'rows_skipped_count' => $import->getSkippedCount(),
            'skipped_rows' => $import->getSkippedRows(),
        ]);
    }

    public function getNames()
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_salesman_names";

        $salesmen = app('cache')->store('database')->get($key);

        if (!$salesmen) {
            $salesmen = Salesman::where('active', true)
                ->select('id', 'code', 'name')
                ->orderBy('name')
                ->get()
                ->map(function ($salesman) {
                    return [
                        'id' => $salesman->id,
                        'code' => $salesman->code,
                        'name' => $salesman->name
                    ];
                });

            app('cache')->store('database')->forever($key, $salesmen);
        }

        return response()->json([
            'status' => true,
            'message' => 'Salesman names fetched successfully.',
            'data' => $salesmen,
        ]);
    }
}
