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
        $cacheKey = "payment_terms_{$tenantId}";

        return Cache::remember($cacheKey, 3600, function () {
            return PaymentTerm::all();
        });
    }

    public function store(Request $request)
    {
        $request->validate([
            'code' => 'required|string|unique:payment_terms,code',
            'name' => 'required|string',
            'nb_days' => 'required|integer|min:0',
            'active' => 'boolean',
        ]);

        $paymentTerm = PaymentTerm::create($request->all());
        Cache::forget("payment_terms_" . tenant('id'));

        return response()->json($paymentTerm, 201);
    }

    public function show(PaymentTerm $paymentTerm)
    {
        $tenantId = tenant('id');
        $cacheKey = "payment_term_{$paymentTerm->id}_{$tenantId}";

        return Cache::remember($cacheKey, 3600, function () use ($paymentTerm) {
            return $paymentTerm;
        });
    }

    public function update(Request $request, PaymentTerm $paymentTerm)
    {
        $request->validate([
            'code' => 'required|string|unique:payment_terms,code,' . $paymentTerm->id,
            'name' => 'required|string',
            'nb_days' => 'required|integer|min:0',
            'active' => 'boolean',
        ]);

        $paymentTerm->update($request->all());
        Cache::forget("payment_terms_" . tenant('id'));
        Cache::forget("payment_term_{$paymentTerm->id}_" . tenant('id'));

        return response()->json($paymentTerm);
    }

    public function destroy(PaymentTerm $paymentTerm)
    {
        // Check if payment term has associated customers
        if ($paymentTerm->customers()->exists()) {
            return response()->json(['message' => 'Cannot delete payment term with associated customers'], 422);
        }

        $paymentTerm->delete();
        Cache::forget("payment_terms_" . tenant('id'));
        Cache::forget("payment_term_{$paymentTerm->id}_" . tenant('id'));

        return response()->json(null, 204);
    }

    public function bulkDelete(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:payment_terms,id'
        ]);

        // Check for payment terms with customers
        $termsWithCustomers = PaymentTerm::whereIn('id', $request->ids)
            ->whereHas('customers')
            ->pluck('id');

        if ($termsWithCustomers->isNotEmpty()) {
            return response()->json([
                'message' => 'Some payment terms have associated customers and cannot be deleted',
                'terms_with_customers' => $termsWithCustomers
            ], 422);
        }

        PaymentTerm::whereIn('id', $request->ids)->delete();
        Cache::forget("payment_terms_" . tenant('id'));

        return response()->json(['message' => 'Payment terms deleted successfully']);
    }

    public function exportExcell()
    {
        $paymentTerms = PaymentTerm::all();

        if ($paymentTerms->isEmpty()) {
            return response()->json(['message' => 'No payment terms to export'], 404);
        }

        $fileName = 'payment_terms_' . date('Y-m-d_H-i-s') . '.xlsx';
        return Excel::download(new Export($paymentTerms), $fileName);
    }

    public function exportPdf()
    {
        $paymentTerms = PaymentTerm::all();

        if ($paymentTerms->isEmpty()) {
            return response()->json(['message' => 'No payment terms to export'], 404);
        }

        $fileName = 'payment_terms_' . date('Y-m-d_H-i-s') . '.pdf';
        return Excel::download(new ExportPDF($paymentTerms), $fileName);
    }

    public function importFromExcel(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:xlsx,xls',
        ]);

        try {
            $import = new DynamicExcelImport(PaymentTerm::class);
            Excel::import($import, $request->file('file'));

            Cache::forget("payment_terms_" . tenant('id'));

            return response()->json(['message' => 'Payment terms imported successfully']);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error importing payment terms: ' . $e->getMessage()], 500);
        }
    }
}
