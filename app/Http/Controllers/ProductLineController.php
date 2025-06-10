<?php

namespace App\Http\Controllers;

use App\Models\ProductLine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Imports\DynamicExcelImport;

class ProductLineController extends Controller
{
    public function index()
    {
        $tenantId = tenant('id');
        $cacheKey = "product_lines_{$tenantId}";

        return Cache::remember($cacheKey, 3600, function () {
            return ProductLine::all();
        });
    }

    public function store(Request $request)
    {
        $request->validate([
            'code' => 'required|string|unique:product_lines,code',
            'name' => 'required|string',
            'is_inactive' => 'boolean',
        ]);

        $productLine = ProductLine::create($request->all());
        Cache::forget("product_lines_" . tenant('id'));

        return response()->json($productLine, 201);
    }

    public function show(ProductLine $productLine)
    {
        $tenantId = tenant('id');
        $cacheKey = "product_line_{$productLine->id}_{$tenantId}";

        return Cache::remember($cacheKey, 3600, function () use ($productLine) {
            return $productLine;
        });
    }

    public function update(Request $request, ProductLine $productLine)
    {
        $request->validate([
            'code' => 'required|string|unique:product_lines,code,' . $productLine->id,
            'name' => 'required|string',
            'is_inactive' => 'boolean',
        ]);

        $productLine->update($request->all());
        Cache::forget("product_lines_" . tenant('id'));
        Cache::forget("product_line_{$productLine->id}_" . tenant('id'));

        return response()->json($productLine);
    }

    public function destroy(ProductLine $productLine)
    {
        $productLine->delete();
        Cache::forget("product_lines_" . tenant('id'));
        Cache::forget("product_line_{$productLine->id}_" . tenant('id'));

        return response()->json(null, 204);
    }

    public function bulkDelete(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:product_lines,id'
        ]);

        ProductLine::whereIn('id', $request->ids)->delete();
        Cache::forget("product_lines_" . tenant('id'));

        return response()->json(['message' => 'Product lines deleted successfully']);
    }

    public function exportExcell()
    {
        $productLines = ProductLine::all();

        if ($productLines->isEmpty()) {
            return response()->json(['message' => 'No product lines to export'], 404);
        }

        $fileName = 'product_lines_' . date('Y-m-d_H-i-s') . '.xlsx';
        return Excel::download(new Export($productLines), $fileName);
    }

    public function exportPdf()
    {
        $productLines = ProductLine::all();

        if ($productLines->isEmpty()) {
            return response()->json(['message' => 'No product lines to export'], 404);
        }

        $fileName = 'product_lines_' . date('Y-m-d_H-i-s') . '.pdf';
        return Excel::download(new ExportPDF($productLines), $fileName);
    }

    public function importFromExcel(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:xlsx,xls',
        ]);

        try {
            $import = new DynamicExcelImport(ProductLine::class);
            Excel::import($import, $request->file('file'));

            Cache::forget("product_lines_" . tenant('id'));

            return response()->json(['message' => 'Product lines imported successfully']);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error importing product lines: ' . $e->getMessage()], 500);
        }
    }
}
