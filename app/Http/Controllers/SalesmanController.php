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

        $columns = ['id', 'code', 'name', 'email', 'phone1', 'phone2', 'address', 'is_manager', 'is_supervisor', 'is_collector', 'fix_commission', 'commission_by_item', 'active'];
        $headings = ['ID', 'Code', 'Name', 'Email', 'Phone 1', 'Phone 2', 'Address', 'Is Manager', 'Is Supervisor', 'Is Collector', 'Fix Commission', 'Commission by Item', 'Active'];

        $fileName = 'salesmen_' . date('Y-m-d_H-i-s') . '.xlsx';
        return Excel::download(new Export($salesmen, $columns, $headings), $fileName);
    }

    public function exportPdf(ExportPDF $pdfService)
    {
        $salesmen = Salesman::select('id', 'code', 'name', 'email', 'phone1', 'phone2', 'address', 'is_manager', 'is_supervisor', 'is_collector', 'fix_commission', 'commission_by_item', 'active')->get();

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
            'commission_by_item' => 'Commission by Item',
            'active' => 'Active'
        ];
        $data = $salesmen->toArray();

        $pdf = $pdfService->generatePdf($title, $headers, $data);
        return $pdf->download('Salesmen.pdf');
    }

    public function importFromExcel(Request $request)
    {
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
            Salesman::truncate();
        }

        // If type is 'mapping', use provided mapping, else use default
        $mapping = $request->input('mapping');

        $import = new DynamicExcelImport(
            Salesman::class,
            ['code', 'name', 'email', 'phone1', 'phone2', 'address', 'is_manager', 'is_supervisor', 'is_collector', 'fix_commission', 'commission_by_item', 'active'],
            function ($row) {
                foreach ($row as $k => $v) { if (is_string($v)) { $row[$k] = trim($v); } }
                $errors = [];

                if (($row['code'] ?? '') === '') {
                    $errors[] = 'Missing code';
                }
                if (($row['name'] ?? '') === '') {
                    $errors[] = 'Missing name';
                }
                if (!empty($row['email']) && !filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
                    $errors[] = 'Invalid email format';
                }

                return $errors;
            },
            function ($row) {
                foreach ($row as $k => $v) { if (is_string($v)) { $row[$k] = trim($v); } }
                return [
                    'code' => $row['code'] ?? null,
                    'name' => $row['name'] ?? null,
                    'email' => $row['email'] ?? null,
                    'phone1' => $row['phone1'] ?? null,
                    'phone2' => $row['phone2'] ?? null,
                    'address' => $row['address'] ?? null,
                    'is_manager' => boolval($row['is_manager'] ?? false),
                    'is_supervisor' => boolval($row['is_supervisor'] ?? false),
                    'is_collector' => boolval($row['is_collector'] ?? false),
                    'fix_commission' => isset($row['fix_commission']) ? floatval($row['fix_commission']) : 0,
                    'commission_by_item' => isset($row['commission_by_item']) ? floatval($row['commission_by_item']) : 0,
                    'active' => boolval($row['active'] ?? true),
                ];
            }
        );

        Excel::import($import, $request->file('file'));

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_salesmen");

        $imported = $import->getImportedCount();
        $skippedCount = $import->getSkippedCount();
        $skippedRows = $import->getSkippedRows();
        $totalProcessed = $imported + $skippedCount;

        $message = '';
        if ($imported > 0 && $skippedCount === 0) {
            $message = "Imported {$imported} row(s) successfully.";
        } elseif ($imported > 0 && $skippedCount > 0) {
            $message = "Partially imported: {$imported} row(s) added, {$skippedCount} row(s) skipped.";
        } elseif ($imported === 0 && $skippedCount > 0) {
            $message = 'No rows imported. All rows were skipped due to validation errors or duplicates.';
        } else {
            $message = 'No rows found to import.';
        }

        return response()->json([
            'success' => $imported > 0,
            'message' => $message,
            'rows_processed' => $totalProcessed,
            'rows_imported' => $imported,
            'rows_skipped_count' => $skippedCount,
            'skipped_rows' => $skippedRows,
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
