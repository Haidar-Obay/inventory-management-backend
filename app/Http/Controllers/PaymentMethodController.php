<?php

namespace App\Http\Controllers;

use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Http\Requests\PaymentMethod\StorePaymentMethodRequest;
use App\Http\Requests\PaymentMethod\UpdatePaymentMethodRequest;
use App\Imports\DynamicExcelImport;
use App\Models\PaymentMethod;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class PaymentMethodController extends Controller
{
    public function index()
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_payment_methods";

        $paymentMethods = app('cache')->store('database')->get($key);

        if (! $paymentMethods) {
            $paymentMethods = PaymentMethod::orderBy('id')->get();
            app('cache')->store('database')->forever($key, $paymentMethods);
        }

        return response()->json([
            'status' => true,
            'message' => 'Payment methods fetched successfully.',
            'data' => $paymentMethods,
        ]);
    }

    public function store(StorePaymentMethodRequest $request)
    {
        $validated = $request->validated();

        $nextId = $this->computeNextAvailableId(PaymentMethod::class, 'id');
        $paymentMethod = new PaymentMethod($validated);
        $paymentMethod->id = $nextId;
        $paymentMethod->save();
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
        if (! $cached) {
            $cached = $paymentMethod;
            app('cache')->store('database')->forever($key, $cached);
        }

        return response()->json([
            'status' => true,
            'message' => 'Payment method details fetched successfully.',
            'data' => $cached,
        ]);
    }

    public function update(UpdatePaymentMethodRequest $request, PaymentMethod $paymentMethod)
    {
        $validated = $request->validated();

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
        // Prevent deletion if related customers or suppliers exist; include helpful details
        $customersCount = $paymentMethod->customers()->count();
        $suppliersCount = \App\Models\Supplier::where('payment_method_id', $paymentMethod->id)->count();

        if ($customersCount > 0 || $suppliersCount > 0) {
            $details = [];
            if ($customersCount > 0) {
                $details['customers'] = [
                    'count' => $customersCount,
                    'sample_ids' => $paymentMethod->customers()->select('customers.id')->limit(1)->pluck('id'),
                ];
            }
            if ($suppliersCount > 0) {
                $details['suppliers'] = [
                    'count' => $suppliersCount,
                    'sample_ids' => \App\Models\Supplier::where('payment_method_id', $paymentMethod->id)
                        ->select('suppliers.id')
                        ->limit(1)
                        ->pluck('id'),
                ];
            }

            $identifier = $paymentMethod->name ?? $paymentMethod->code ?? "ID: {$paymentMethod->id}";

            return response()->json([
                'status' => false,
                'message' => "Cannot delete payment method \"{$identifier}\" (ID: {$paymentMethod->id}). It is referenced by existing customers or suppliers.",
                'details' => $details,
            ], 409);
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
            if (! $method) {
                $skipped[] = [
                    'id' => $id,
                    'name' => "ID: {$id}",
                    'reason' => 'Payment method not found.',
                ];

                continue;
            }

            // Check if the payment method has any customers or suppliers linked to it and include details
            $customersCount = $method->customers()->count();
            $suppliersCount = \App\Models\Supplier::where('payment_method_id', $method->id)->count();

            if ($customersCount > 0 || $suppliersCount > 0) {
                $details = [];
                if ($customersCount > 0) {
                    $details['customers'] = [
                        'count' => $customersCount,
                        'sample_ids' => $method->customers()->select('customers.id')->limit(1)->pluck('id'),
                    ];
                }
                if ($suppliersCount > 0) {
                    $details['suppliers'] = [
                        'count' => $suppliersCount,
                        'sample_ids' => \App\Models\Supplier::where('payment_method_id', $method->id)
                            ->select('suppliers.id')
                            ->limit(1)
                            ->pluck('id'),
                    ];
                }

                $identifier = $method->name ?? $method->code ?? "ID: {$id}";
                $skipped[] = [
                    'id' => $id,
                    'name' => $identifier,
                    'reason' => 'Cannot delete payment method. It is referenced by existing customers or suppliers.',
                    'details' => $details,
                ];

                continue;
            }

            try {
                $method->delete();
                $deleted++;
                app('cache')->store('database')->forget("tenant_{$tenantId}_payment_method_{$id}");
            } catch (\Illuminate\Database\QueryException $e) {
                // Check if it's a foreign key constraint error and include details
                if ($e->getCode() == '23503') {
                    $details = [];

                    try {
                        $customersCount = $method?->customers()->count() ?? 0;
                        $suppliersCount = $method ? \App\Models\Supplier::where('payment_method_id', $method->id)->count() : 0;
                        if ($customersCount > 0) {
                            $details['customers'] = [
                                'count' => $customersCount,
                                'sample_ids' => $method->customers()->select('customers.id')->limit(1)->pluck('id'),
                            ];
                        }
                        if ($suppliersCount > 0) {
                            $details['suppliers'] = [
                                'count' => $suppliersCount,
                                'sample_ids' => \App\Models\Supplier::where('payment_method_id', $method->id)
                                    ->select('suppliers.id')
                                    ->limit(1)
                                    ->pluck('id'),
                            ];
                        }
                    } catch (\Throwable $ignored) {
                    }

                    $method = PaymentMethod::find($id);
                    $identifier = $method?->name ?? $method?->code ?? "ID: {$id}";
                    $skipped[] = [
                        'id' => $id,
                        'name' => $identifier,
                        'reason' => 'Cannot delete payment method. It is referenced by existing customers or suppliers.',
                        'details' => $details,
                    ];
                } else {
                    $method = PaymentMethod::find($id);
                    $identifier = $method?->name ?? $method?->code ?? "ID: {$id}";
                    $skipped[] = [
                        'id' => $id,
                        'name' => $identifier,
                        'reason' => $e->getMessage(),
                    ];
                }
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
        $columns = ['id', 'code', 'name', 'is_credit_card', 'is_online_payment', 'active',
            'created_at',
            'updated_at'];
        $headings = ['ID', 'Code', 'Name', 'Is Credit Card', 'Is Online Payment', 'Active',
            'Created At', 'Updated At'];

        return Excel::download(new Export($paymentMethods, $columns, $headings), 'payment_methods.xlsx');
    }

    public function exportPdf(ExportPDF $pdfService)
    {
        $paymentMethods = PaymentMethod::select('id', 'code', 'name', 'is_credit_card', 'is_online_payment', 'active', 'created_at', 'updated_at')->get();
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
            'created_at' => 'Created At',
            'updated_at' => 'Updated At',
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
            function ($row) use ($mapping) {
                foreach ($row as $k => $v) {
                    if (is_string($v)) {
                        $row[$k] = trim($v);
                    }
                }
                $errors = [];
                $codeKey = $mapping ? array_search('code', $mapping) : 'code';
                $nameKey = $mapping ? array_search('name', $mapping) : 'name';
                $creditKey = $mapping ? array_search('is_credit_card', $mapping) : 'is_credit_card';
                $onlineKey = $mapping ? array_search('is_online_payment', $mapping) : 'is_online_payment';
                $activeKey = $mapping ? array_search('active', $mapping) : 'active';
                if ((($row[$codeKey] ?? '') === '')) {
                    $errors[] = 'Missing code';
                }
                if ((($row[$nameKey] ?? '') === '')) {
                    $errors[] = 'Missing name';
                }
                if (! isset($row[$creditKey])) {
                    $errors[] = 'Missing is_credit_card';
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
                $creditKey = $mapping ? array_search('is_credit_card', $mapping) : 'is_credit_card';
                $onlineKey = $mapping ? array_search('is_online_payment', $mapping) : 'is_online_payment';
                $activeKey = $mapping ? array_search('active', $mapping) : 'active';

                return [
                    'code' => $row[$codeKey] ?? null,
                    'name' => $row[$nameKey] ?? null,
                    'is_credit_card' => isset($row[$creditKey]) ? (bool) $row[$creditKey] : false,
                    'is_online_payment' => isset($row[$onlineKey]) ? (bool) $row[$onlineKey] : false,
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

        app('cache')->store('database')->forget('tenant_'.tenant('id').'_payment_methods');

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
