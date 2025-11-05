<?php

namespace App\Http\Controllers;

use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Http\Requests\StoreServiceCategoryRequest;
use App\Http\Requests\UpdateServiceCategoryRequest;
use App\Imports\DynamicExcelImport;
use App\Models\ServiceCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;

class ServiceCategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ServiceCategory::query()->with(['department:id,name']);

        if ($request->filled('name')) {
            $query->where('name', 'like', '%'.$request->input('name').'%');
        }

        $categories = $query->orderBy('name')->get();

        // Flatten department name into response while keeping department_id
        $categories = $categories->map(function ($c) {
            $arr = $c->toArray();
            $arr['department_name'] = optional($c->department)->name;
            // Optionally hide the nested relation to keep payload small
            unset($arr['department']);

            return $arr;
        });

        return response()->json($categories);
    }

    public function store(StoreServiceCategoryRequest $request): JsonResponse
    {
        $data = $request->validated();
        $nextId = $this->computeNextAvailableId(ServiceCategory::class, 'id');
        $category = new ServiceCategory($data);
        $category->id = $nextId;
        $category->save();

        return response()->json($category, 201);
    }

    public function show(ServiceCategory $serviceCategory): JsonResponse
    {
        $serviceCategory->loadMissing(['department:id,name']);
        $data = $serviceCategory->toArray();
        $data['department_name'] = optional($serviceCategory->department)->name;
        unset($data['department']);

        return response()->json($data);
    }

    public function update(UpdateServiceCategoryRequest $request, ServiceCategory $serviceCategory): JsonResponse
    {
        $serviceCategory->update($request->validated());

        return response()->json($serviceCategory);
    }

    public function destroy(ServiceCategory $serviceCategory): JsonResponse
    {
        // Prevent deletion if related services exist; include helpful details
        $servicesCount = $serviceCategory->services()->count();
        if ($servicesCount > 0) {
            $sampleIds = $serviceCategory->services()->select('services.id')->limit(1)->pluck('id');

            return response()->json([
                'status' => false,
                'message' => 'Cannot delete service category. It is referenced by existing services.',
                'details' => [
                    'services' => [
                        'count' => $servicesCount,
                        'sample_ids' => $sampleIds,
                    ],
                ],
            ], 409);
        }

        $serviceCategory->delete();

        return response()->json(['message' => 'Deleted']);
    }

    public function exportExcell()
    {
        $serviceCategories = ServiceCategory::query()->with(['department:id,name']);
        $collection = $serviceCategories->get();

        if ($collection->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'No service categories to export',
            ], 404);
        }

        $columns = [
            'id',
            'name',
            'description',
            'department.name',
            'created_at',
            'updated_at',
        ];

        $headings = [
            'ID', 'Name', 'Description', 'Department', 'Created At', 'Updated At',
        ];

        $fileName = 'service_categories_'.'.xlsx';

        return Excel::download(new Export($serviceCategories, $columns, $headings), $fileName);
    }

    public function exportPdf(ExportPDF $pdfService)
    {
        $serviceCategories = ServiceCategory::with(['department:id,name'])
            ->select('id', 'name', 'description', 'department_id', 'created_at', 'updated_at')
            ->get();

        if ($serviceCategories->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'No service categories to export',
            ], 404);
        }

        $title = 'Service Categories Report';
        $headers = [
            'id' => 'ID',
            'name' => 'Name',
            'description' => 'Description',
            'department.name' => 'Department',
            'created_at' => 'Created At',
            'updated_at' => 'Updated At',
        ];

        // Normalize department name for PDF
        $data = $serviceCategories->map(function ($category) {
            return [
                'id' => $category->id,
                'name' => $category->name,
                'description' => $category->description,
                'department.name' => optional($category->department)->name,
                'created_at' => $category->created_at,
                'updated_at' => $category->updated_at,
            ];
        })->toArray();

        $pdf = $pdfService->generatePdf($title, $headers, $data);

        return $pdf->download('service_categories_'.'.pdf');
    }

    public function importFromExcel(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv',
            'type' => 'nullable|string|in:fresh,mapping',
            'mapping' => 'nullable|array',
        ]);

        // If type is 'fresh', delete all records first
        if ($request->input('type') === 'fresh') {
            ServiceCategory::truncate();
        }

        $import = new DynamicExcelImport(
            ServiceCategory::class,
            [
                'name',
                'description',
            ],
            function ($row) {
                // Normalize inputs (trim strings)
                foreach ($row as $k => $v) {
                    if (is_string($v)) {
                        $row[$k] = trim($v);
                    }
                }

                $errors = [];

                if (empty($row['name'])) {
                    $errors[] = 'Missing name';
                }

                return $errors;
            },
            function ($row) {
                // Normalize inputs (trim strings)
                foreach ($row as $k => $v) {
                    if (is_string($v)) {
                        $row[$k] = trim($v);
                    }
                }

                return [
                    'name' => $row['name'] ?? null,
                    'description' => $row['description'] ?? null,
                ];
            },
            true // Enable header validation
        );

        try {
            Excel::import($import, $request->file('file'));
        } catch (\Illuminate\Database\QueryException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Import failed due to duplicate or invalid data. Please review your file and try again.',
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Import failed. Please check the file format and data.',
            ], 422);
        }

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

    public function bulkDelete(Request $request)
    {
        // Log the incoming request for debugging
        Log::info('Bulk delete request received', [
            'ids' => $request->input('ids'),
            'ids_type' => gettype($request->input('ids')),
            'ids_count' => is_array($request->input('ids')) ? count($request->input('ids')) : 0,
        ]);

        $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'required|integer|exists:service_categories,id',
        ], [
            'ids.required' => 'The ids field is required.',
            'ids.array' => 'The ids must be an array.',
            'ids.min' => 'At least one ID must be provided.',
            'ids.*.required' => 'Each ID is required.',
            'ids.*.integer' => 'Each ID must be an integer.',
            'ids.*.exists' => 'One or more selected service categories do not exist.',
        ]);

        $skipped = [];
        $deleted = 0;

        foreach ($request->ids as $id) {
            $serviceCategory = ServiceCategory::find($id);

            // Check if there are any services linked to this category and include details
            if ($serviceCategory->services()->exists()) {
                $servicesCount = $serviceCategory->services()->count();
                $details = [
                    'services' => [
                        'count' => $servicesCount,
                        'sample_ids' => $serviceCategory->services()->select('services.id')->limit(1)->pluck('id'),
                    ],
                ];

                $skipped[] = [
                    'id' => $id,
                    'reason' => 'Cannot delete service category. It is referenced by existing services.',
                    'details' => $details,
                ];

                continue;
            }

            try {
                $deleted += $serviceCategory->delete();
            } catch (\Illuminate\Database\QueryException $e) {
                // Check if it's a foreign key constraint error and include details
                if ($e->getCode() == '23503') {
                    $details = [];

                    try {
                        $serviceCategory = ServiceCategory::find($id);
                        $servicesCount = $serviceCategory?->services()->count() ?? 0;
                        if ($servicesCount > 0) {
                            $details['services'] = [
                                'count' => $servicesCount,
                                'sample_ids' => $serviceCategory->services()->select('services.id')->limit(1)->pluck('id'),
                            ];
                        }
                    } catch (\Throwable $ignored) {
                    }

                    $skipped[] = [
                        'id' => $id,
                        'reason' => 'Cannot delete service category. It is referenced by existing services.',
                        'details' => $details,
                    ];
                } else {
                    $skipped[] = ['id' => $id, 'reason' => $e->getMessage()];
                }
            }
        }

        return response()->json([
            'message' => 'Bulk delete completed.',
            'deleted_count' => $deleted,
            'skipped' => $skipped,
        ]);
    }
}
