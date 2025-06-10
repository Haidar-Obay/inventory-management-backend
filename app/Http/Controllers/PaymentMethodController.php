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
        $cacheKey = "payment_methods_{$tenantId}";

        return Cache::remember($cacheKey, 3600, function () {
            return PaymentMethod::all();
        });
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string|max:50|unique:payment_methods,code',
            'name' => 'required|string|max:255',
            'is_credit_card' => 'required|boolean',
            'is_inactive' => 'required|boolean',
        ]);

        $paymentMethod = PaymentMethod::create($validated);
        Cache::forget("payment_methods_" . tenant('id'));

        return response()->json($paymentMethod, 201);
    }

    public function show(PaymentMethod $paymentMethod)
    {
        $tenantId = tenant('id');
        $cacheKey = "payment_method_{$paymentMethod->id}_{$tenantId}";

        return Cache::remember($cacheKey, 3600, function () use ($paymentMethod) {
            return $paymentMethod;
        });
    }

    public function update(Request $request, PaymentMethod $paymentMethod)
    {
        $validated = $request->validate([
            'code' => 'required|string|max:50|unique:payment_methods,code,' . $paymentMethod->id,
            'name' => 'required|string|max:255',
            'is_credit_card' => 'required|boolean',
            'is_inactive' => 'required|boolean',
        ]);

        $paymentMethod->update($validated);
        Cache::forget("payment_methods_" . tenant('id'));
        Cache::forget("payment_method_{$paymentMethod->id}_" . tenant('id'));

        return response()->json($paymentMethod);
    }

    public function destroy(PaymentMethod $paymentMethod)
    {
        if ($paymentMethod->customers()->exists()) {
            return response()->json(['message' => 'Cannot delete payment method with associated customers'], 422);
        }

        $paymentMethod->delete();
        Cache::forget("payment_methods_" . tenant('id'));
        Cache::forget("payment_method_{$paymentMethod->id}_" . tenant('id'));

        return response()->json(null, 204);
    }

    public function bulkDelete(Request $request)
    {
        $ids = $request->input('ids');

        if (!$ids || !is_array($ids)) {
            return response()->json(['message' => 'No payment methods selected'], 400);
        }

        $paymentMethods = PaymentMethod::whereIn('id', $ids)->get();
        $errors = [];

        foreach ($paymentMethods as $paymentMethod) {
            if ($paymentMethod->customers()->exists()) {
                $errors[] = "Payment method {$paymentMethod->name} has associated customers and cannot be deleted";
            }
        }

        if (!empty($errors)) {
            return response()->json(['message' => 'Some payment methods could not be deleted', 'errors' => $errors], 422);
        }

        PaymentMethod::whereIn('id', $ids)->delete();
        Cache::forget("payment_methods_" . tenant('id'));

        return response()->json(['message' => 'Payment methods deleted successfully']);
    }

    public function exportExcell()
    {
        $paymentMethods = PaymentMethod::all();

        if ($paymentMethods->isEmpty()) {
            return response()->json(['message' => 'No payment methods to export'], 404);
        }

        $fileName = 'payment_methods_' . date('Y-m-d_H-i-s') . '.xlsx';
        return Excel::download(new Export($paymentMethods), $fileName);
    }

    public function exportPdf()
    {
        $paymentMethods = PaymentMethod::all();

        if ($paymentMethods->isEmpty()) {
            return response()->json(['message' => 'No payment methods to export'], 404);
        }

        $fileName = 'payment_methods_' . date('Y-m-d_H-i-s') . '.pdf';
        return Excel::download(new ExportPDF($paymentMethods), $fileName);
    }

    public function importFromExcel(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:xlsx,xls',
        ]);

        try {
            $import = new DynamicExcelImport(new PaymentMethod());
            Excel::import($import, $request->file('file'));
            Cache::forget("payment_methods_" . tenant('id'));

            return response()->json(['message' => 'Payment methods imported successfully']);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error importing payment methods: ' . $e->getMessage()], 500);
        }
    }
}
