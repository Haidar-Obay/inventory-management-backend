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
            'file' => 'required|file|mimes:xlsx,xls,csv',
        ]);

        $import = new DynamicExcelImport(
            PaymentMethod::class,
            ['code', 'name', 'is_credit_card', 'is_online_payment', 'active'],
            function ($row) {
                $errors = [];
                if (empty($row['code'])) {
                    $errors[] = 'Missing code';
                }
                if (empty($row['name'])) {
                    $errors[] = 'Missing name';
                }
                if (!isset($row['is_credit_card'])) {
                    $errors[] = 'Missing is_credit_card';
                }
                return $errors;
            },
            function ($row) {
                return [
                    'code' => $row['code'],
                    'name' => $row['name'],
                    'is_credit_card' => isset($row['is_credit_card']) ? (bool)$row['is_credit_card'] : false,
                    'is_online_payment' => isset($row['is_online_payment']) ? (bool)$row['is_online_payment'] : false,
                    'active' => isset($row['active']) ? (bool)$row['active'] : true,
                ];
            }
        );

        Excel::import($import, $request->file('file'));
        app('cache')->store('database')->forget('tenant_' . tenant('id') . '_payment_methods');

        return response()->json([
            'success' => true,
            'rows_imported' => $import->getImportedCount(),
            'rows_skipped_count' => $import->getSkippedCount(),
            'skipped_rows' => $import->getSkippedRows(),
        ]);
    }
}

