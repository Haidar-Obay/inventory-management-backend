<?php

namespace App\Http\Controllers;

use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Imports\DynamicExcelImport;
use App\Models\PaymentTerm;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class PaymentTermController extends Controller
{
    public function index()
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_payment_terms";

        $paymentTerms = app('cache')->store('database')->get($key);

        if (! $paymentTerms) {
            $paymentTerms = PaymentTerm::orderBy('id')->get();
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
            'name' => 'required|string|unique:payment_terms,name',
            'nb_days' => 'required|integer|min:0',
            'active' => 'boolean',
        ]);

        $nextId = $this->computeNextAvailableId(PaymentTerm::class, 'id');
        $paymentTerm = new PaymentTerm($validated);
        $paymentTerm->id = $nextId;
        $paymentTerm->save();
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
        if (! $cached) {
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
            'code' => 'required|string|unique:payment_terms,code,'.$paymentTerm->id,
            'name' => 'required|string|unique:payment_terms,name,'.$paymentTerm->id,
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
        // Prevent deletion if related customers or suppliers exist; include helpful details
        $customersCount = $paymentTerm->customers()->count();
        $suppliersCount = \App\Models\Supplier::where('payment_term_id', $paymentTerm->id)->count();

        if ($customersCount > 0 || $suppliersCount > 0) {
            $details = [];
            if ($customersCount > 0) {
                $details['customers'] = [
                    'count' => $customersCount,
                    'sample_ids' => $paymentTerm->customers()->select('customers.id')->limit(1)->pluck('id'),
                ];
            }
            if ($suppliersCount > 0) {
                $details['suppliers'] = [
                    'count' => $suppliersCount,
                    'sample_ids' => \App\Models\Supplier::where('payment_term_id', $paymentTerm->id)
                        ->select('suppliers.id')
                        ->limit(1)
                        ->pluck('id'),
                ];
            }

            $identifier = $paymentTerm->name ?? $paymentTerm->code ?? "ID: {$paymentTerm->id}";

            return response()->json([
                'status' => false,
                'message' => "Cannot delete payment term \"{$identifier}\" (ID: {$paymentTerm->id}). It is referenced by existing customers or suppliers.",
                'details' => $details,
            ], 409);
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
            if (! $term) {
                $skipped[] = [
                    'id' => $id,
                    'name' => "ID: {$id}",
                    'reason' => 'Payment term not found.',
                ];

                continue;
            }

            // Check if the payment term has any customers or suppliers linked to it and include details
            $customersCount = $term->customers()->count();
            $suppliersCount = \App\Models\Supplier::where('payment_term_id', $term->id)->count();

            if ($customersCount > 0 || $suppliersCount > 0) {
                $details = [];
                if ($customersCount > 0) {
                    $details['customers'] = [
                        'count' => $customersCount,
                        'sample_ids' => $term->customers()->select('customers.id')->limit(1)->pluck('id'),
                    ];
                }
                if ($suppliersCount > 0) {
                    $details['suppliers'] = [
                        'count' => $suppliersCount,
                        'sample_ids' => \App\Models\Supplier::where('payment_term_id', $term->id)
                            ->select('suppliers.id')
                            ->limit(1)
                            ->pluck('id'),
                    ];
                }

                $identifier = $term->name ?? $term->code ?? "ID: {$id}";
                $skipped[] = [
                    'id' => $id,
                    'name' => $identifier,
                    'reason' => 'Cannot delete payment term. It is referenced by existing customers or suppliers.',
                    'details' => $details,
                ];

                continue;
            }

            try {
                $term->delete();
                $deleted++;
                app('cache')->store('database')->forget("tenant_{$tenantId}_payment_term_{$id}");
            } catch (\Illuminate\Database\QueryException $e) {
                // Check if it's a foreign key constraint error and include details
                if ($e->getCode() == '23503') {
                    $details = [];

                    try {
                        $customersCount = $term?->customers()->count() ?? 0;
                        $suppliersCount = $term ? \App\Models\Supplier::where('payment_term_id', $term->id)->count() : 0;
                        if ($customersCount > 0) {
                            $details['customers'] = [
                                'count' => $customersCount,
                                'sample_ids' => $term->customers()->select('customers.id')->limit(1)->pluck('id'),
                            ];
                        }
                        if ($suppliersCount > 0) {
                            $details['suppliers'] = [
                                'count' => $suppliersCount,
                                'sample_ids' => \App\Models\Supplier::where('payment_term_id', $term->id)
                                    ->select('suppliers.id')
                                    ->limit(1)
                                    ->pluck('id'),
                            ];
                        }
                    } catch (\Throwable $ignored) {
                    }

                    $term = PaymentTerm::find($id);
                    $identifier = $term?->name ?? $term?->code ?? "ID: {$id}";
                    $skipped[] = [
                        'id' => $id,
                        'name' => $identifier,
                        'reason' => 'Cannot delete payment term. It is referenced by existing customers or suppliers.',
                        'details' => $details,
                    ];
                } else {
                    $term = PaymentTerm::find($id);
                    $identifier = $term?->name ?? $term?->code ?? "ID: {$id}";
                    $skipped[] = [
                        'id' => $id,
                        'name' => $identifier,
                        'reason' => $e->getMessage(),
                    ];
                }
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
        $paymentTerms = PaymentTerm::orderBy('id');
        $collection = $paymentTerms->get();
        if ($collection->isEmpty()) {
            return response()->json(['message' => 'No payment terms found.'], 404);
        }
        $columns = ['id', 'code', 'name', 'nb_days', 'active',
            'created_at',
            'updated_at'];
        $headings = ['ID', 'Code', 'Name', 'Number of Days', 'Active',
            'Created At', 'Updated At'];

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
            PaymentTerm::truncate();
        }

        // If type is 'mapping', use provided mapping, else use default
        $mapping = $request->input('mapping');

        $import = new DynamicExcelImport(
            PaymentTerm::class,
            ['code', 'name', 'nb_days', 'active'],
            function ($row) use ($mapping) {
                foreach ($row as $k => $v) {
                    if (is_string($v)) {
                        $row[$k] = trim($v);
                    }
                }
                $errors = [];
                $codeKey = $mapping ? array_search('code', $mapping) : 'code';
                $nameKey = $mapping ? array_search('name', $mapping) : 'name';
                $daysKey = $mapping ? array_search('nb_days', $mapping) : 'nb_days';
                $activeKey = $mapping ? array_search('active', $mapping) : 'active';
                if ((($row[$codeKey] ?? '') === '')) {
                    $errors[] = 'Missing code';
                }
                if ((($row[$nameKey] ?? '') === '')) {
                    $errors[] = 'Missing name';
                }
                if (! isset($row[$daysKey]) || ! is_numeric($row[$daysKey])) {
                    $errors[] = 'Invalid or missing nb_days';
                }

                return $errors;
            },
            function ($row) use ($mapping) {
                foreach ($row as $k => $v) {
                    if (is_string($v)) {
                        $row[$k] = trim($v);
                    }
                }
                $codeKey = $mapping ? array_search('code', $mapping) : 'code';
                $nameKey = $mapping ? array_search('name', $mapping) : 'name';
                $daysKey = $mapping ? array_search('nb_days', $mapping) : 'nb_days';
                $activeKey = $mapping ? array_search('active', $mapping) : 'active';

                return [
                    'code' => $row[$codeKey] ?? null,
                    'name' => $row[$nameKey] ?? null,
                    'nb_days' => $row[$daysKey] ?? null,
                    'active' => isset($row[$activeKey]) ? (bool) $row[$activeKey] : true,
                ];
            },
            $mapping ? false : true // Disable header validation when mapping provided
        );

        Excel::import($import, $request->file('file'));

        // Check if headers were valid
        if (! $import->areHeadersValid()) {
            $headerResult = $import->getHeaderValidationResult();

            return response()->json([
                'success' => false,
                'message' => 'Invalid Excel file headers',
                'header_validation' => $headerResult,
                'errors' => [
                    'missing_headers' => $headerResult['missing'],
                    'extra_headers' => $headerResult['extra'],
                    'expected_headers' => $headerResult['expected_headers'],
                    'actual_headers' => $headerResult['excel_headers'],
                ],
            ], 422);
        }

        app('cache')->store('database')->forget('tenant_'.tenant('id').'_payment_terms');

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
