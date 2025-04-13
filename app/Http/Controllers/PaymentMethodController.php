<?php

namespace App\Http\Controllers;

use App\Models\PaymentMethod;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Imports\DynamicExcelImport;

class PaymentMethodController extends Controller
{
    public function index()
    {
        $methods = PaymentMethod::orderBy('name')->get();

        return response()->json([
            'status' => true,
            'message' => 'Payment methods fetched successfully.',
            'data' => $methods,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:payment_methods,name',
            'is_credit_card' => 'nullable|boolean',
            'is_online_payment' => 'nullable|boolean',
            'is_inactive' => 'nullable|boolean',
        ]);

        $method = PaymentMethod::create($validated);

        return response()->json([
            'status' => true,
            'message' => 'Payment method created successfully.',
            'data' => $method,
        ], 201);
    }

    public function show(PaymentMethod $paymentMethod)
    {
        return response()->json([
            'status' => true,
            'message' => 'Payment method details fetched successfully.',
            'data' => $paymentMethod,
        ]);
    }

    public function update(Request $request, PaymentMethod $paymentMethod)
    {
        $validated = $request->validate([
            'name' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('payment_methods')->ignore($paymentMethod->id),
            ],
            'is_credit_card' => 'nullable|boolean',
            'is_online_payment' => 'nullable|boolean',
            'is_inactive' => 'nullable|boolean',
        ]);

        $paymentMethod->update($validated);

        return response()->json([
            'status' => true,
            'message' => 'Payment method updated successfully.',
            'data' => $paymentMethod,
        ]);
    }

    public function destroy(PaymentMethod $paymentMethod)
    {
        $paymentMethod->delete();

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

        $skipped = [];
        $deleted = 0;

        foreach ($request->ids as $id) {
            try {
                $deleted += PaymentMethod::where('id', $id)->delete();
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
        $paymentMethod = PaymentMethod::query();
        $collection = $paymentMethod->get();
        if ($collection->isEmpty()) {
            return response()->json(['message' => 'No payment_Methods found.'], 404);
        }
        $columns = ['id', 'name'];
        $headings = ['ID', 'Name'];
        return Excel::download(new Export($paymentMethod, $columns, $headings), 'payment_methods.xlsx');

    }

    public function exportPdf(ExportPDF $pdfService)
    {
        $paymentMethods = PaymentMethod::select(
            'id',
            'name'
        )->get();

        if ($paymentMethods->isEmpty()) {
            return response()->json(['message' => 'No payment methods found.'], 404);
        }

        $title = 'Payment Method Report';
        $headers = [
            'id' => 'Payment Method ID',
            'name' => 'Payment Method Name'
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
            ['name', 'is_credit_card', 'is_online_payment', 'is_inactive'],
            function ($row) {
                $errors = [];

                if (empty($row['name'])) {
                    $errors[] = 'Missing name';
                }

                foreach (['is_credit_card', 'is_online_payment', 'is_inactive'] as $field) {
                    if (!isset($row[$field]) || !in_array($row[$field], [0, 1, '0', '1'], true)) {
                        $errors[] = "$field must be 0 or 1";
                    }
                }

                return $errors;
            },
            function ($row) {
                return [
                    'name' => $row['name'],
                    'is_credit_card' => (bool) $row['is_credit_card'],
                    'is_online_payment' => (bool) $row['is_online_payment'],
                    'is_inactive' => (bool) $row['is_inactive'],
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
