<?php

namespace App\Http\Controllers;

use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Http\Requests\UpdateServiceAdvancedPricingRequest;
use App\Imports\DynamicExcelImport;
use App\Models\Service;
use App\Models\ServiceAdvancedPricing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;

class ServiceAdvancedPricingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ServiceAdvancedPricing::query()->with(['service:id,name', 'specialist:id,name']);
        if ($request->filled('service_id')) {
            $query->where('service_id', $request->integer('service_id'));
        }
        if ($request->filled('specialist_id')) {
            $query->where('specialist_id', $request->integer('specialist_id'));
        }

        return response()->json($query->orderByDesc('id')->paginate());
    }

    public function store(Request $request): JsonResponse
    {
        $payload = $request->all();

        if (isset($payload[0]) && is_array($payload[0])) {
            $rules = [
                'service_id' => ['required', 'integer', 'exists:services,id'],
                'specialist_id' => ['required', 'integer', 'exists:specialists,id'],
                'price_on_site' => ['required', 'numeric', 'min:0'],
                'price_on_call' => ['required', 'numeric', 'min:0'],
            ];
            $created = [];
            $errors = [];
            DB::beginTransaction();

            try {
                foreach ($payload as $index => $row) {
                    $validator = Validator::make($row, $rules);
                    if ($validator->fails()) {
                        $errors[] = ['index' => $index, 'errors' => $validator->errors()];

                        continue;
                    }
                    $created[] = ServiceAdvancedPricing::create($validator->validated())
                        ->load(['service:id,name', 'specialist:id,name']);
                }
                if (! empty($errors) && empty($created)) {
                    DB::rollBack();

                    return response()->json(['success' => false, 'errors' => $errors], 422);
                }
                DB::commit();
            } catch (\Throwable $e) {
                DB::rollBack();

                throw $e;
            }

            return response()->json(['success' => true, 'created_count' => count($created), 'errors' => $errors, 'data' => $created], 201);
        }

        $validator = Validator::make($payload, [
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'specialist_id' => ['required', 'integer', 'exists:specialists,id'],
            'price_on_site' => ['required', 'numeric', 'min:0'],
            'price_on_call' => ['required', 'numeric', 'min:0'],
        ]);
        $validator->validate();
        $pricing = ServiceAdvancedPricing::create($validator->validated());

        return response()->json($pricing->load(['service:id,name', 'specialist:id,name']), 201);
    }

    public function show(ServiceAdvancedPricing $serviceAdvancedPricing): JsonResponse
    {
        return response()->json($serviceAdvancedPricing->load(['service:id,name', 'specialist:id,name']));
    }

    public function update(UpdateServiceAdvancedPricingRequest $request, ServiceAdvancedPricing $serviceAdvancedPricing): JsonResponse
    {
        $serviceAdvancedPricing->update($request->validated());

        return response()->json($serviceAdvancedPricing->load(['service:id,name', 'specialist:id,name']));
    }

    public function destroy(ServiceAdvancedPricing $serviceAdvancedPricing): JsonResponse
    {
        $serviceAdvancedPricing->delete();

        return response()->json(['message' => 'Deleted']);
    }

    public function indexByService(Service $service): JsonResponse
    {
        $rows = ServiceAdvancedPricing::with('specialist:id,name')
            ->where('service_id', $service->id)
            ->orderByDesc('id')
            ->get();

        return response()->json($rows);
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer', 'exists:service_advanced_pricings,id'],
        ]);
        $skipped = [];
        $deleted = 0;
        foreach ($request->ids as $id) {
            try {
                $deleted += ServiceAdvancedPricing::where('id', $id)->delete();
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
        $query = ServiceAdvancedPricing::query();
        $collection = $query->get();
        if ($collection->isEmpty()) {
            return response()->json(['message' => 'No rows found.'], 404);
        }

        $columns = ['id', 'service_id', 'specialist_id', 'price_on_site', 'price_on_call', 'created_at', 'updated_at'];
        $headings = ['ID', 'Service ID', 'Specialist ID', 'Price On Site', 'Price On Call', 'Created At', 'Updated At'];
        $fileName = 'service_advanced_pricings_'.date('Y-m-d_H-i-s').'.xlsx';

        return Excel::download(new Export($query, $columns, $headings), $fileName);
    }

    public function exportPdf(ExportPDF $pdfService)
    {
        $rows = ServiceAdvancedPricing::select('id', 'service_id', 'specialist_id', 'price_on_site', 'price_on_call')->get();
        if ($rows->isEmpty()) {
            return response()->json(['message' => 'No rows found.'], 404);
        }
        $title = 'Service Advanced Pricing';
        $headers = [
            'id' => 'ID',
            'service_id' => 'Service ID',
            'specialist_id' => 'Specialist ID',
            'price_on_site' => 'Price On Site',
            'price_on_call' => 'Price On Call',
            'created_at' => 'Created At',
            'updated_at' => 'Updated At'];
        $pdf = $pdfService->generatePdf($title, $headers, $rows->toArray());

        return $pdf->download('ServiceAdvancedPricing.pdf');
    }

    public function importFromExcel(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:xlsx,xls,csv']);

        $import = new DynamicExcelImport(
            ServiceAdvancedPricing::class,
            ['service_id', 'specialist_id', 'price_on_site', 'price_on_call'],
            function ($row) {
                $errors = [];
                if (empty($row['service_id'])) {
                    $errors[] = 'Missing service_id';
                }
                if (empty($row['specialist_id'])) {
                    $errors[] = 'Missing specialist_id';
                }
                if (isset($row['price_on_site']) && ! is_numeric($row['price_on_site'])) {
                    $errors[] = 'price_on_site must be numeric';
                }
                if (isset($row['price_on_call']) && ! is_numeric($row['price_on_call'])) {
                    $errors[] = 'price_on_call must be numeric';
                }

                return $errors;
            },
            function ($row) {
                return [
                    'service_id' => (int) $row['service_id'],
                    'specialist_id' => (int) $row['specialist_id'],
                    'price_on_site' => isset($row['price_on_site']) ? (float) $row['price_on_site'] : 0,
                    'price_on_call' => isset($row['price_on_call']) ? (float) $row['price_on_call'] : 0,
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
