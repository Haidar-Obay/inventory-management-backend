<?php

namespace App\Http\Controllers;

use App\Models\PaymentTerm;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Imports\DynamicExcelImport;

class PaymentTermController extends Controller
{
    public function index()
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_payment_terms";

        $paymentTerms = app('cache')->store('database')->get($key);

        if (!$paymentTerms) {
            $paymentTerms = PaymentTerm::orderBy('name')->get();
            app('cache')->store('database')->forever($key, $paymentTerms);
        }

        return response()->json([
            'status' => true,
            'message' => 'Payment terms fetched successfully.',
            'data' => $paymentTerms,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string|unique:payment_terms,code',
            'name' => 'required|string',
            'nb_days' => 'required|integer|min:0',
            'active' => 'boolean',
        ]);

        $paymentTerm = PaymentTerm::create($validated);
        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_payment_terms");

        return response()->json([
            'status' => true,
            'message' => 'Payment term created successfully.',
            'data' => $paymentTerm,
        ], 201);
    }

    public function show(PaymentTerm $paymentTerm)
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_payment_term_{$paymentTerm->id}";

        $cached = app('cache')->store('database')->get($key);
        if (!$cached) {
            $cached = $paymentTerm;
            app('cache')->store('database')->forever($key, $cached);
        }

        return response()->json([
            'status' => true,
            'message' => 'Payment term details fetched successfully.',
            'data' => $cached,
        ]);
    }

    public function update(Request $request, PaymentTerm $paymentTerm)
    {
        $validated = $request->validate([
            'code' => 'required|string|unique:payment_terms,code,' . $paymentTerm->id,
            'name' => 'required|string',
            'nb_days' => 'required|integer|min:0',
            'active' => 'boolean',
        ]);

        $paymentTerm->update($validated);
        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_payment_terms");
        app('cache')->store('database')->forget("tenant_{$tenantId}_payment_term_{$paymentTerm->id}");

        return response()->json([
            'status' => true,
            'message' => 'Payment term updated successfully.',
            'data' => $paymentTerm,
        ]);
    }

    public function destroy(PaymentTerm $paymentTerm)
    {
        if ($paymentTerm->customers()->exists()) {
            return response()->json([
                'status' => false,
                'message' => 'Cannot delete payment term with associated customers',
            ], 422);
        }

        $paymentTerm->delete();
        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_payment_terms");
        app('cache')->store('database')->forget("tenant_{$tenantId}_payment_term_{$paymentTerm->id}");

        return response()->json([
            'status' => true,
            'message' => 'Payment term deleted successfully.',
        ]);
    }

    public function bulkDelete(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:payment_terms,id',
        ]);

        $tenantId = tenant('id');
        $skipped = [];
        $deleted = 0;

        foreach ($request->ids as $id) {
            $term = PaymentTerm::find($id);
            if ($term && !$term->customers()->exists()) {
                $term->delete();
                $deleted++;
                app('cache')->store('database')->forget("tenant_{$tenantId}_payment_term_{$id}");
            } else {
                $skipped[] = [
                    'id' => $id,
                    'reason' => $term ? 'Has associated customers' : 'Not found',
                ];
            }
        }
        app('cache')->store('database')->forget("tenant_{$tenantId}_payment_terms");

        return response()->json([
            'message' => 'Bulk delete completed.',
            'deleted_count' => $deleted,
            'skipped' => $skipped,
        ]);
    }

    public function exportExcell()
    {
        $paymentTerms = PaymentTerm::orderBy('name');
        $collection = $paymentTerms->get();
        if ($collection->isEmpty()) {
            return response()->json(['message' => 'No payment terms found.'], 404);
        }
        $columns = ['id', 'code', 'name', 'nb_days', 'active'];
        $headings = ['ID', 'Code', 'Name', 'Number of Days', 'Active'];
        return Excel::download(new Export($paymentTerms, $columns, $headings), 'payment_terms.xlsx');
    }

    public function exportPdf(ExportPDF $pdfService)
    {
        $paymentTerms = PaymentTerm::select('id', 'code', 'name', 'nb_days', 'active')->get();
        if ($paymentTerms->isEmpty()) {
            return response()->json(['message' => 'No payment terms found.'], 404);
        }
        $title = 'Payment Terms Report';
        $headers = [
            'id' => 'ID',
            'code' => 'Code',
            'name' => 'Name',
            'nb_days' => 'Number of Days',
            'active' => 'Active',
        ];
        $data = $paymentTerms->toArray();
        $pdf = $pdfService->generatePdf($title, $headers, $data);
        return $pdf->download('PaymentTerms.pdf');
    }

    public function importFromExcel(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv',
        ]);

        $import = new DynamicExcelImport(
            PaymentTerm::class,
            ['code', 'name', 'nb_days', 'active'],
            function ($row) {
                $errors = [];
                if (empty($row['code'])) {
                    $errors[] = 'Missing code';
                }
                if (empty($row['name'])) {
                    $errors[] = 'Missing name';
                }
                if (!isset($row['nb_days']) || !is_numeric($row['nb_days'])) {
                    $errors[] = 'Invalid or missing nb_days';
                }
                return $errors;
            },
            function ($row) {
                return [
                    'code' => $row['code'],
                    'name' => $row['name'],
                    'nb_days' => $row['nb_days'],
                    'active' => isset($row['active']) ? (bool)$row['active'] : true,
                ];
            }
        );

        Excel::import($import, $request->file('file'));
        app('cache')->store('database')->forget('tenant_' . tenant('id') . '_payment_terms');

        return response()->json([
            'success' => true,
            'rows_imported' => $import->getImportedCount(),
            'rows_skipped_count' => $import->getSkippedCount(),
            'skipped_rows' => $import->getSkippedRows(),
        ]);
    }
}
