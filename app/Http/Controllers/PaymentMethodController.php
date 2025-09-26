<?php

namespace App\Http\Controllers;

use App\Models\PaymentMethod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Imports\DynamicExcelImport;

class PaymentMethodController extends Controller
{
    public function index()
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_payment_methods";

        $paymentMethods = app('cache')->store('database')->get($key);

        if (!$paymentMethods) {
            $paymentMethods = PaymentMethod::orderBy('id')->get();
            app('cache')->store('database')->forever($key, $paymentMethods);
        }

        return response()->json([
            'status' => true,
            'message' => 'Payment methods fetched successfully.',
            'data' => $paymentMethods,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string|max:50|unique:payment_methods,code',
            'name' => 'required|string|max:255|unique:payment_methods,name',
            'is_credit_card' => 'required|boolean',
            'is_online_payment' => 'required|boolean',
            'active' => 'required|boolean',
        ]);

        $paymentMethod = PaymentMethod::create($validated);
        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_payment_methods");

        return response()->json([
            'status' => true,
            'message' => 'Payment method created successfully.',
            'data' => $paymentMethod,
        ], 201);
    }

    public function show(PaymentMethod $paymentMethod)
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_payment_method_{$paymentMethod->id}";

        $cached = app('cache')->store('database')->get($key);
        if (!$cached) {
            $cached = $paymentMethod;
            app('cache')->store('database')->forever($key, $cached);
        }

        return response()->json([
            'status' => true,
            'message' => 'Payment method details fetched successfully.',
            'data' => $cached,
        ]);
    }

    public function update(Request $request, PaymentMethod $paymentMethod)
    {
        $validated = $request->validate([
            'code' => 'required|string|max:50|unique:payment_methods,code,' . $paymentMethod->id,
            'name' => 'required|string|max:255|unique:payment_methods,name,' . $paymentMethod->id,
            'is_credit_card' => 'required|boolean',
            'is_online_payment' => 'required|boolean',
            'active' => 'required|boolean',
        ]);

        $paymentMethod->update($validated);
        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_payment_methods");
        app('cache')->store('database')->forget("tenant_{$tenantId}_payment_method_{$paymentMethod->id}");

        return response()->json([
            'status' => true,
            'message' => 'Payment method updated successfully.',
            'data' => $paymentMethod,
        ]);
    }

    public function destroy(PaymentMethod $paymentMethod)
    {
        if ($paymentMethod->customers()->exists()) {
            return response()->json([
                'status' => false,
                'message' => 'Cannot delete payment method with associated customers',
            ], 422);
        }

        $paymentMethod->delete();
        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_payment_methods");
        app('cache')->store('database')->forget("tenant_{$tenantId}_payment_method_{$paymentMethod->id}");

        return response()->json([
            'status' => true,
            'message' => 'Payment method deleted successfully.',
        ]);
    }

    public function bulkDelete(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:payment_methods,id',
        ]);

        $tenantId = tenant('id');
        $skipped = [];
        $deleted = 0;

        foreach ($request->ids as $id) {
            $method = PaymentMethod::find($id);
            if ($method && !$method->customers()->exists()) {
                $method->delete();
                $deleted++;
                app('cache')->store('database')->forget("tenant_{$tenantId}_payment_method_{$id}");
            } else {
                $skipped[] = [
                    'id' => $id,
                    'reason' => $method ? 'Has associated customers' : 'Not found',
                ];
            }
        }
        app('cache')->store('database')->forget("tenant_{$tenantId}_payment_methods");

        return response()->json([
            'message' => 'Bulk delete completed.',
            'deleted_count' => $deleted,
            'skipped' => $skipped,
        ]);
    }

    public function exportExcell()
    {
        $paymentMethods = PaymentMethod::orderBy('id');
        $collection = $paymentMethods->get();
        if ($collection->isEmpty()) {
            return response()->json(['message' => 'No payment methods found.'], 404);
        }
        $columns = ['id', 'code', 'name', 'is_credit_card', 'is_online_payment', 'active'];
        $headings = ['ID', 'Code', 'Name', 'Is Credit Card', 'Is Online Payment', 'Active'];
        return Excel::download(new Export($paymentMethods, $columns, $headings), 'payment_methods.xlsx');
    }

    public function exportPdf(ExportPDF $pdfService)
    {
        $paymentMethods = PaymentMethod::select('id', 'code', 'name', 'is_credit_card', 'is_online_payment', 'active')->get();
        if ($paymentMethods->isEmpty()) {
            return response()->json(['message' => 'No payment methods found.'], 404);
        }
        $title = 'Payment Methods Report';
        $headers = [
            'id' => 'ID',
            'code' => 'Code',
            'name' => 'Name',
            'is_credit_card' => 'Is Credit Card',
            'is_online_payment' => 'Is Online Payment',
            'active' => 'Active',
        ];
        $data = $paymentMethods->toArray();
        $pdf = $pdfService->generatePdf($title, $headers, $data);
        return $pdf->download('PaymentMethods.pdf');
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
            PaymentMethod::truncate();
        }

        // If type is 'mapping', use provided mapping, else use default
        $mapping = $request->input('mapping');

        $import = new DynamicExcelImport(
            PaymentMethod::class,
            ['code', 'name', 'is_credit_card', 'is_online_payment', 'active'],
            function ($row) {
                foreach ($row as $k => $v) { if (is_string($v)) { $row[$k] = trim($v); } }
                $errors = [];
                if (($row['code'] ?? '') === '') {
                    $errors[] = 'Missing code';
                }
                if (($row['name'] ?? '') === '') {
                    $errors[] = 'Missing name';
                }
                if (!isset($row['is_credit_card'])) {
                    $errors[] = 'Missing is_credit_card';
                }
                return $errors;
            },
            function ($row) {
                foreach ($row as $k => $v) { if (is_string($v)) { $row[$k] = trim($v); } }
                return [
                    'code' => $row['code'] ?? null,
                    'name' => $row['name'] ?? null,
                    'is_credit_card' => isset($row['is_credit_card']) ? (bool)$row['is_credit_card'] : false,
                    'is_online_payment' => isset($row['is_online_payment']) ? (bool)$row['is_online_payment'] : false,
                    'active' => isset($row['active']) ? (bool)$row['active'] : true,
                ];
            },
            true // Enable header validation
        );

        Excel::import($import, $request->file('file'));

        // Check if headers were valid
        if (!$import->areHeadersValid()) {
            $headerResult = $import->getHeaderValidationResult();
            return response()->json([
                'success' => false,
                'message' => 'Invalid Excel file headers',
                'header_validation' => $headerResult,
                'errors' => [
                    'missing_headers' => $headerResult['missing'],
                    'extra_headers' => $headerResult['extra'],
                    'expected_headers' => $headerResult['expected_headers'],
                    'actual_headers' => $headerResult['excel_headers']
                ]
            ], 422);
        }

        app('cache')->store('database')->forget('tenant_' . tenant('id') . '_payment_methods');

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
}

