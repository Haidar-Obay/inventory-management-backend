<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreServiceCategoryRequest;
use App\Http\Requests\UpdateServiceCategoryRequest;
use App\Models\ServiceCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Imports\DynamicExcelImport;

class ServiceCategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ServiceCategory::query();

        if ($request->filled('name')) {
            $query->where('name', 'like', '%' . $request->input('name') . '%');
        }

        $categories = $query->orderBy('name')->paginate(10);
        return response()->json($categories);
    }

    public function store(StoreServiceCategoryRequest $request): JsonResponse
    {
        $category = ServiceCategory::create($request->validated());
        return response()->json($category, 201);
    }

    public function show(ServiceCategory $serviceCategory): JsonResponse
    {
        return response()->json($serviceCategory);
    }

    public function update(UpdateServiceCategoryRequest $request, ServiceCategory $serviceCategory): JsonResponse
    {
        $serviceCategory->update($request->validated());
        return response()->json($serviceCategory);
    }

    public function destroy(ServiceCategory $serviceCategory): JsonResponse
    {
        $serviceCategory->delete();
        return response()->json(['message' => 'Deleted']);
    }

    public function exportExcell()
    {
        $query = ServiceCategory::query();
        $collection = $query->get();

        if ($collection->isEmpty()) {
            return response()->json(['message' => 'No service categories found.'], 404);
        }

        $columns = [
            'id',
            'name',
            'description',
            'created_at',
            'updated_at',
        ];

        $headings = [
            'ID', 'Name', 'Description', 'Created At', 'Updated At'
        ];

        $fileName = 'service_categories_' . date('Y-m-d_H-i-s') . '.xlsx';
        return Excel::download(new Export($query, $columns, $headings), $fileName);
    }

    public function exportPdf(ExportPDF $pdfService)
    {
        $categories = ServiceCategory::select('id', 'name', 'description')->get();

        if ($categories->isEmpty()) {
            return response()->json(['message' => 'No service categories found.'], 404);
        }

        $title = 'Service Categories Report';
        $headers = [
            'id' => 'ID',
            'name' => 'Name',
            'description' => 'Description',
        ];

        $pdf = $pdfService->generatePdf($title, $headers, $categories->toArray());
        return $pdf->download('Service_Categories.pdf');
    }

    public function importFromExcel(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv',
        ]);

        $import = new DynamicExcelImport(
            ServiceCategory::class,
            [
                'name',
                'description',
            ],
            function ($row) {
                $errors = [];

                if (empty($row['name'])) {
                    $errors[] = 'Missing name';
                }

                return $errors;
            },
            function ($row) {
                return [
                    'name' => $row['name'],
                    'description' => $row['description'] ?? null,
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

    public function bulkDelete(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:service_categories,id',
        ]);

        $skipped = [];
        $deleted = 0;

        foreach ($request->ids as $id) {
            try {
                $deleted += ServiceCategory::where('id', $id)->delete();
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
}