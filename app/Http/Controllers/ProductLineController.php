<?php

namespace App\Http\Controllers;

use App\Models\ProductLine;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Imports\DynamicExcelImport;

class ProductLineController extends Controller
{
    public function index()
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_product_lines";

        $productLines = app('cache')->store('database')->get($key);

        if (!$productLines) {
            $productLines = ProductLine::get();
            app('cache')->store('database')->forever($key, $productLines);
        }

        return response()->json([
            'status' => true,
            'message' => 'Product lines fetched successfully.',
            'data' => $productLines,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'code' => 'required|string|unique:product_lines,code',
            'name' => 'required|string',
            'active' => 'boolean',
        ]);

        $productLine = ProductLine::create($request->all());

        // Invalidate cache after creating new product line
        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_product_lines");

        return response()->json([
            'status' => true,
            'message' => 'Product line created successfully.',
            'data' => $productLine,
        ]);
    }

    public function show(ProductLine $productLine)
    {
        return response()->json([
            'status' => true,
            'message' => 'Product line fetched successfully.',
            'data' => $productLine,
        ]);
    }

    public function update(Request $request, ProductLine $productLine)
    {
        $request->validate([
            'code' => 'required|string|unique:product_lines,code,' . $productLine->id,
            'name' => 'required|string',
            'active' => 'boolean',
        ]);

        $productLine->update($request->all());

        // Invalidate cache after updating product line
        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_product_lines");

        return response()->json([
            'status' => true,
            'message' => 'Product line updated successfully.',
            'data' => $productLine,
        ]);
    }

    public function destroy(ProductLine $productLine)
    {
        $productLine->delete();

        // Invalidate cache after deleting product line
        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_product_lines");

        return response()->json([
            'status' => true,
            'message' => 'Product line deleted successfully.',
        ]);
    }

    public function bulkDelete(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:product_lines,id'
        ]);

        ProductLine::whereIn('id', $request->ids)->delete();

        // Invalidate cache after bulk delete
        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_product_lines");

        return response()->json([
            'status' => true,
            'message' => 'Product lines deleted successfully.',
        ]);
    }

    public function exportExcel()
    {
        $productLines = ProductLine::orderBy('name');
        $collection = $productLines->get();

        if ($collection->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'No product lines to export',
            ], 404);
        }

        $columns = ['id', 'code', 'name', 'active'];
        $headings = ['ID', 'Code', 'Name', 'Active'];

        $fileName = 'product_lines_' . date('Y-m-d_H-i-s') . '.xlsx';
        return Excel::download(new Export($productLines, $columns, $headings), $fileName);
    }

    public function exportPdf(ExportPDF $pdfService)
    {
        $productLines = ProductLine::select('id', 'code', 'name', 'active')->get();

        if ($productLines->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'No product lines to export',
            ], 404);
        }

        $title = 'Product Lines Report';
        $headers = [
            'id' => 'ID',
            'code' => 'Code', 
            'name' => 'Name',
            'active' => 'Active'
        ];

        $data = $productLines->toArray();
        $pdf = $pdfService->generatePdf($title, $headers, $data);
        
        return $pdf->download('product_lines_' . date('Y-m-d_H-i-s') . '.pdf');
    }

    public function importFromExcel(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:xlsx,xls',
        ]);

        try {
            $import = new DynamicExcelImport(ProductLine::class);
            Excel::import($import, $request->file('file'));

            // Invalidate cache after import
            $tenantId = tenant('id');
            app('cache')->store('database')->forget("tenant_{$tenantId}_product_lines");

            return response()->json([
                'status' => true,
                'message' => 'Product lines imported successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Error importing product lines: ' . $e->getMessage(),
            ], 500);
        }
    }
}
