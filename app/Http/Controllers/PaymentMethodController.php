<?php

namespace App\Http\Controllers;

use App\Models\PaymentMethod;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\Export;
use App\Exports\ExportPDF;

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
    public function export()
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
}
