<?php

namespace App\Http\Controllers;

use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Http\Requests\StoreServiceRequest;
use App\Http\Requests\UpdateServiceRequest;
use App\Imports\DynamicExcelImport;
use App\Models\AssociationServicePrice;
use App\Models\ReferrerServiceCommission;
use App\Models\Service;
use App\Models\ServiceAdvancedPricing;
use App\Models\ServiceNeededItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class ServiceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Service::query()->with(['serviceCategory:id,name,description', 'department:id,name', 'subDepartment:id,name', 'specialists:id,name']);

        if ($request->filled('category_id')) {
            $query->where('service_category_id', $request->integer('category_id'));
        }
        if ($request->filled('department_id')) {
            $query->where('department_id', $request->integer('department_id'));
        }
        if ($request->filled('sub_department_id')) {
            $query->where('sub_department_id', $request->integer('sub_department_id'));
        }
        if ($request->filled('specialist_id')) {
            $query->whereHas('specialists', function ($q) use ($request) {
                $q->where('specialist_id', $request->integer('specialist_id'));
            });
        }
        if ($request->filled('active')) {
            $query->where('active', filter_var($request->input('active'), FILTER_VALIDATE_BOOLEAN));
        }

        $services = $query->orderBy('name')->paginate(10);
        // Hide raw FK ids and image, but keep other related models
        $services->getCollection()->transform(function ($service) {
            return $service->makeHidden(['service_category_id', 'department_id', 'sub_department_id', 'image']);
        });

        return response()->json($services);
    }

    public function store(StoreServiceRequest $request): JsonResponse
    {
        $data = $request->validated();

        $specialistIds = $data['specialist_ids'] ?? [];
        unset($data['specialist_ids']);

        // Handle image upload (file or base64 data URL or plain URL string)
        if (request()->hasFile('image')) {
            $path = Storage::disk('public')->putFile('services', request()->file('image'));
            $data['image'] = Storage::url($path);
        } elseif (! empty($data['image']) && is_string($data['image'])) {
            $imageString = $data['image'];
            if (str_starts_with($imageString, 'data:image')) {
                // Decode base64 data URL and store as file
                [$_meta, $base64Data] = explode(',', $imageString, 2);
                $binary = base64_decode($base64Data, true);
                if ($binary !== false) {
                    $filename = 'services/'.uniqid('svc_', true).'.png';
                    Storage::disk('public')->put($filename, $binary);
                    $data['image'] = Storage::url($filename);
                } else {
                    // If decode fails, drop the image to avoid oversized string insert
                    unset($data['image']);
                }
            } elseif (filter_var($imageString, FILTER_VALIDATE_URL)) {
                // Keep as-is if it is a valid URL
                $data['image'] = $imageString;
            } else {
                // Unknown format; drop to avoid DB overflow
                unset($data['image']);
            }
        }

        $service = Service::create($data);
        if (! empty($specialistIds)) {
            $service->specialists()->sync($specialistIds);
        }
        $service->load(['serviceCategory:id,name,description', 'department:id,name', 'subDepartment:id,name', 'specialists:id,name']);

        return response()->json([
            'status' => true,
            'message' => 'Service created successfully.',
            'data' => $service,
        ], 201);
    }

    public function show(Service $service): JsonResponse
    {
        $loaded = $service->load([
            'serviceCategory:id,name,description',
            'department:id,name',
            'subDepartment:id,name',
            'specialists:id,name',
        ]);

        // Attach advanced pricing (with specialist)
        $advancedPricings = ServiceAdvancedPricing::with('specialist:id,name')
            ->where('service_id', $service->id)
            ->get();
        $loaded->setRelation('advanced_pricings', $advancedPricings);

        // Attach needed items (with asset)
        $neededItems = ServiceNeededItem::with('asset:id,name')
            ->where('service_id', $service->id)
            ->get();
        $loaded->setRelation('needed_items', $neededItems);

        // Attach association pricing rules (with association)
        $associationPrices = AssociationServicePrice::with('association:id,name')
            ->where('service_id', $service->id)
            ->get();
        $loaded->setRelation('association_prices', $associationPrices);

        // Attach referrer rules (with referrer)
        $referrerRules = ReferrerServiceCommission::with('referrer:id,name')
            ->where('service_id', $service->id)
            ->get();
        $loaded->setRelation('referrer_rules', $referrerRules);

        // Hide raw IDs but keep related objects
        $loaded->makeHidden(['service_category_id', 'department_id', 'sub_department_id']);

        return response()->json([
            'status' => true,
            'message' => 'Service details fetched successfully.',
            'data' => $loaded,
        ]);
    }

    public function update(UpdateServiceRequest $request, Service $service): JsonResponse
    {
        $data = $request->validated();
        $specialistIds = $data['specialist_ids'] ?? null;
        unset($data['specialist_ids']);

        if (request()->hasFile('image')) {
            $path = Storage::disk('public')->putFile('services', request()->file('image'));
            $data['image'] = Storage::url($path);
        } elseif (array_key_exists('image', $data) && is_string($data['image'])) {
            $imageString = $data['image'];
            if (str_starts_with($imageString, 'data:image')) {
                [$_meta, $base64Data] = explode(',', $imageString, 2);
                $binary = base64_decode($base64Data, true);
                if ($binary !== false) {
                    $filename = 'services/'.uniqid('svc_', true).'.png';
                    Storage::disk('public')->put($filename, $binary);
                    $data['image'] = Storage::url($filename);
                } else {
                    unset($data['image']);
                }
            } elseif (! empty($imageString) && filter_var($imageString, FILTER_VALIDATE_URL)) {
                $data['image'] = $imageString;
            } elseif ($imageString === null || $imageString === '') {
                // Explicitly clearing image
                $data['image'] = null;
            } else {
                unset($data['image']);
            }
        }

        $service->update($data);
        if (is_array($specialistIds)) {
            $service->specialists()->sync($specialistIds);
        }
        $service->load(['serviceCategory:id,name,description', 'department:id,name', 'subDepartment:id,name', 'specialists:id,name']);

        return response()->json([
            'status' => true,
            'message' => 'Service updated successfully.',
            'data' => $service,
        ]);
    }

    public function destroy(Service $service): JsonResponse
    {
        $service->delete();

        return response()->json(['message' => 'Deleted']);
    }

    public function exportExcell()
    {
        $services = Service::query()->with([
            'serviceCategory:id,name',
            'department:id,name',
            'subDepartment:id,name',
            'specialists:id,name',
        ]);
        $collection = $services->get();

        if ($collection->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'No services to export',
            ], 404);
        }

        $columns = [
            'id',
            'name',
            'serviceCategory.name',
            'cnss_code',
            'result_after_days',
            'needs_specialist',
            'duration_minutes',
            'normal_price',
            'vip_price',
            'price_in_group',
            'event_pricing',
            'price_calculated_by_hour',
            'hour_price',
            'estimated_cost',
            'service_color',
            'service_sex',
            'active',
            'department.name',
            'subDepartment.name',
            'specialists.*.name',
            'created_at',
            'updated_at',
        ];

        $headings = [
            'ID', 'Name', 'Service Category', 'CNSS Code', 'Result After Days', 'Needs Specialist', 'Duration (min)',
            'Normal Price', 'VIP Price', 'Price In Group', 'Event Pricing',
            'Price Calculated by Hour', 'Hour Price', 'Estimated Cost',
            'Service Color', 'Service Sex', 'Active',
            'Department', 'Sub Department', 'Specialists',
            'Created At', 'Updated At',
        ];

        $fileName = 'services_'.'.xlsx';

        return Excel::download(new Export($services, $columns, $headings), $fileName);
    }

    public function exportPdf(ExportPDF $pdfService)
    {
        $services = Service::query()
            ->with([
                'serviceCategory:id,name',
                'department:id,name',
                'subDepartment:id,name',
                'specialists:id,name',
            ])
            ->get([
                'id', 'name', 'service_category_id', 'cnss_code', 'result_after_days', 'needs_specialist',
                'duration_minutes', 'normal_price', 'vip_price', 'price_in_group', 'event_pricing',
                'price_calculated_by_hour', 'hour_price', 'estimated_cost', 'service_color', 'service_sex',
                'active', 'department_id', 'sub_department_id', 'created_at', 'updated_at',
            ]);

        if ($services->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'No services to export',
            ], 404);
        }

        $title = 'Services Report';
        $headers = [
            'id' => 'ID',
            'name' => 'Name',
            'serviceCategory.name' => 'Service Category',
            'cnss_code' => 'CNSS Code',
            'result_after_days' => 'Result After Days',
            'needs_specialist' => 'Needs Specialist',
            'duration_minutes' => 'Duration (min)',
            'normal_price' => 'Normal Price',
            'vip_price' => 'VIP Price',
            'price_in_group' => 'Price In Group',
            'event_pricing' => 'Event Pricing',
            'price_calculated_by_hour' => 'Price Calculated by Hour',
            'hour_price' => 'Hour Price',
            'estimated_cost' => 'Estimated Cost',
            'service_color' => 'Service Color',
            'service_sex' => 'Service Sex',
            'active' => 'Active',
            'department.name' => 'Department',
            'subDepartment.name' => 'Sub Department',
            'specialists' => 'Specialists',
            'created_at' => 'Created At',
            'updated_at' => 'Updated At',
        ];

        // Normalize specialists to array of names for PDF
        $data = $services->map(function ($s) {
            return [
                'id' => $s->id,
                'name' => $s->name,
                'serviceCategory.name' => optional($s->serviceCategory)->name,
                'cnss_code' => $s->cnss_code,
                'result_after_days' => $s->result_after_days,
                'needs_specialist' => $s->needs_specialist,
                'duration_minutes' => $s->duration_minutes,
                'normal_price' => $s->normal_price,
                'vip_price' => $s->vip_price,
                'price_in_group' => $s->price_in_group,
                'event_pricing' => $s->event_pricing,
                'price_calculated_by_hour' => $s->price_calculated_by_hour,
                'hour_price' => $s->hour_price,
                'estimated_cost' => $s->estimated_cost,
                'service_color' => $s->service_color,
                'service_sex' => $s->service_sex,
                'active' => $s->active,
                'department.name' => optional($s->department)->name,
                'subDepartment.name' => optional($s->subDepartment)->name,
                'specialists' => $s->specialists->pluck('name')->values()->all(),
                'created_at' => $s->created_at,
                'updated_at' => $s->updated_at,
            ];
        })->toArray();

        $pdf = $pdfService->generatePdf($title, $headers, $data);

        return $pdf->download('services_'.'.pdf');
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

        try {
            // If type is 'fresh', delete all records first so duplicate detection does not skip rows
            if ($request->input('type') === 'fresh') {
                Service::truncate();
            }

            $import = new DynamicExcelImport(
                Service::class,
                [
                    'name',
                    'service_category_id',
                    'department_id',
                    'sub_department_id',
                    'cnss_code',
                    'result_after_days',
                    'needs_specialist',
                    'specialist_id',
                    'duration_minutes',
                    'normal_price',
                    'vip_price',
                    'price_in_group',
                    'event_pricing',
                    'price_calculated_by_hour',
                    'hour_price',
                    'estimated_cost',
                    'image',
                    'service_color',
                    'service_sex',
                    'active',
                ],
                function ($row) {
                    foreach ($row as $k => $v) {
                        if (is_string($v)) {
                            $row[$k] = trim($v);
                        }
                    }
                    $errors = [];

                    if (empty($row['name'])) {
                        $errors[] = 'Missing name';
                    }

                    if (! empty($row['needs_specialist']) && empty($row['specialist_id'])) {
                        $errors[] = 'specialist_id required when needs_specialist is true';
                    }

                    if (! empty($row['price_calculated_by_hour']) && (empty($row['hour_price']) || ! is_numeric($row['hour_price']))) {
                        $errors[] = 'hour_price required and numeric when price_calculated_by_hour is true';
                    }

                    return $errors;
                },
                function ($row) {
                    foreach ($row as $k => $v) {
                        if (is_string($v)) {
                            $row[$k] = trim($v);
                        }
                    }
                    $toBool = function ($val) {
                        if (is_bool($val)) {
                            return $val;
                        }
                        $val = strtolower((string) $val);

                        return in_array($val, ['1', 'true', 'yes', 'y']);
                    };

                    return [
                        'name' => $row['name'] ?? null,
                        'service_category_id' => $row['service_category_id'] ?? null,
                        'department_id' => $row['department_id'] ?? null,
                        'sub_department_id' => $row['sub_department_id'] ?? null,
                        'cnss_code' => $row['cnss_code'] ?? null,
                        'result_after_days' => isset($row['result_after_days']) ? (int) $row['result_after_days'] : null,
                        'needs_specialist' => $toBool($row['needs_specialist'] ?? false),
                        'specialist_id' => $row['specialist_id'] ?? null,
                        'duration_minutes' => isset($row['duration_minutes']) ? (int) $row['duration_minutes'] : null,
                        'normal_price' => isset($row['normal_price']) ? (float) $row['normal_price'] : null,
                        'vip_price' => isset($row['vip_price']) ? (float) $row['vip_price'] : null,
                        'price_in_group' => isset($row['price_in_group']) ? (float) $row['price_in_group'] : null,
                        'event_pricing' => $toBool($row['event_pricing'] ?? false),
                        'price_calculated_by_hour' => $toBool($row['price_calculated_by_hour'] ?? false),
                        'hour_price' => isset($row['hour_price']) ? (float) $row['hour_price'] : null,
                        'estimated_cost' => isset($row['estimated_cost']) ? (float) $row['estimated_cost'] : null,
                        'image' => $row['image'] ?? null,
                        'service_color' => $row['service_color'] ?? null,
                        'service_sex' => $row['service_sex'] ?? 'both',
                        'active' => $toBool($row['active'] ?? true),
                    ];
                },
                true, // Enable header validation
                $request->input('type') === 'fresh' // Skip duplicate check when fresh
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
                'header_validation' => $import->getHeaderValidationResult(),
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            Log::error('Import failed: '.$e->getMessage(), ['exception' => $e]);

            return response()->json([
                'success' => false,
                'message' => 'Import failed due to invalid data. Please check your file for invalid or missing references.',
                'error_type' => 'database',
            ], 422);
        }
    }

    public function bulkDelete(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:services,id',
        ]);

        $skipped = [];
        $deleted = 0;

        foreach ($request->ids as $id) {
            try {
                $deleted += Service::where('id', $id)->delete();
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
