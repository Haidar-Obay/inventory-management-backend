<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreServiceRequest;
use App\Http\Requests\UpdateServiceRequest;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Imports\DynamicExcelImport;

class ServiceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Service::query()->with(['category:id,name', 'department:id,name', 'subDepartment:id,name', 'specialists:id,name']);

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

        $services = $query->orderBy('name')->paginate();
        return response()->json($services);
    }

    public function store(StoreServiceRequest $request): JsonResponse
    {
        $data = $request->validated();

        $specialistIds = $data['specialist_ids'] ?? [];
        unset($data['specialist_ids']);

        $service = Service::create($data);
        if (!empty($specialistIds)) {
            $service->specialists()->sync($specialistIds);
        }
        return response()->json($service->load(['category:id,name', 'department:id,name', 'subDepartment:id,name', 'specialists:id,name']), 201);
    }

    public function show(Service $service): JsonResponse
    {
        return response()->json($service->load(['category:id,name', 'department:id,name', 'subDepartment:id,name', 'specialist:id,name']));
    }

    public function update(UpdateServiceRequest $request, Service $service): JsonResponse
    {
        $data = $request->validated();
        $specialistIds = $data['specialist_ids'] ?? null;
        unset($data['specialist_ids']);

        $service->update($data);
        if (is_array($specialistIds)) {
            $service->specialists()->sync($specialistIds);
        }
        return response()->json($service->load(['category:id,name', 'department:id,name', 'subDepartment:id,name', 'specialists:id,name']));
    }

    public function destroy(Service $service): JsonResponse
    {
        $service->delete();
        return response()->json(['message' => 'Deleted']);
    }

    public function exportExcell()
    {
        $query = Service::query();
        $collection = $query->get();

        if ($collection->isEmpty()) {
            return response()->json(['message' => 'No services found.'], 404);
        }

        $columns = [
            'id',
            'name',
            'service_category_id',
            'department_id',
            'sub_department_id',
            'cnss_code',
            'result_after_days',
            'needs_specialist',
            // 'specialist_id',
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
            'created_at',
            'updated_at',
        ];

        $headings = [
            'ID', 'Name', 'Category ID', 'Department ID', 'Sub Department ID', 'CNSS Code',
            'Result After Days', 'Needs Specialist', 'Specialist ID', 'Duration (min)',
            'Normal Price', 'VIP Price', 'Price In Group', 'Event Pricing',
            'Calculated By Hour', 'Hour Price', 'Estimated Cost', 'Image',
            'Service Color', 'Service Sex', 'Active', 'Created At', 'Updated At'
        ];

        $fileName = 'services_' . date('Y-m-d_H-i-s') . '.xlsx';
        return Excel::download(new Export($query, $columns, $headings), $fileName);
    }

    public function exportPdf(ExportPDF $pdfService)
    {
        $services = Service::select(
            'id', 'name', 'service_category_id', 'department_id', 'sub_department_id',
            'needs_specialist', /*'specialist_id',*/ 'duration_minutes', 'normal_price', 'vip_price',
            'price_in_group', 'event_pricing', 'price_calculated_by_hour', 'hour_price', 'active'
        )->get();

        if ($services->isEmpty()) {
            return response()->json(['message' => 'No services found.'], 404);
        }

        $title = 'Services Report';
        $headers = [
            'id' => 'ID',
            'name' => 'Name',
            'service_category_id' => 'Category ID',
            'department_id' => 'Department ID',
            'sub_department_id' => 'Sub Department ID',
            'needs_specialist' => 'Needs Specialist',
            'specialist_id' => 'Specialist ID',
            'duration_minutes' => 'Duration (min)',
            'normal_price' => 'Normal Price',
            'vip_price' => 'VIP Price',
            'price_in_group' => 'Price In Group',
            'event_pricing' => 'Event Pricing',
            'price_calculated_by_hour' => 'Calculated By Hour',
            'hour_price' => 'Hour Price',
            'active' => 'Active',
        ];

        $pdf = $pdfService->generatePdf($title, $headers, $services->toArray());
        return $pdf->download('Services.pdf');
    }

    public function importFromExcel(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv',
        ]);

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
                $errors = [];

                if (empty($row['name'])) {
                    $errors[] = 'Missing name';
                }

                if (!empty($row['needs_specialist']) && empty($row['specialist_id'])) {
                    $errors[] = 'specialist_id required when needs_specialist is true';
                }

                if (!empty($row['price_calculated_by_hour']) && (empty($row['hour_price']) || !is_numeric($row['hour_price']))) {
                    $errors[] = 'hour_price required and numeric when price_calculated_by_hour is true';
                }

                return $errors;
            },
            function ($row) {
                $toBool = function ($val) {
                    if (is_bool($val)) return $val;
                    $val = strtolower((string) $val);
                    return in_array($val, ['1', 'true', 'yes', 'y']);
                };

                return [
                    'name' => $row['name'],
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


