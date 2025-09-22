<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAssociationServicePriceRequest;
use App\Http\Requests\UpdateAssociationServicePriceRequest;
use App\Models\Association;
use App\Models\AssociationServicePrice;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Imports\DynamicExcelImport;

class AssociationServicePriceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = AssociationServicePrice::query()->with(['association:id,name', 'service:id,name']);
        if ($request->filled('association_id')) $query->where('association_id', $request->integer('association_id'));
        if ($request->filled('service_id')) $query->where('service_id', $request->integer('service_id'));
        return response()->json($query->orderByDesc('id')->paginate());
    }

    public function store(StoreAssociationServicePriceRequest $request): JsonResponse
    {
        $row = AssociationServicePrice::create($request->validated());
        return response()->json($row->load(['association:id,name', 'service:id,name']), 201);
    }

    public function show(AssociationServicePrice $associationServicePrice): JsonResponse
    {
        return response()->json($associationServicePrice->load(['association:id,name', 'service:id,name']));
    }

    public function update(UpdateAssociationServicePriceRequest $request, AssociationServicePrice $associationServicePrice): JsonResponse
    {
        $associationServicePrice->update($request->validated());
        return response()->json($associationServicePrice->load(['association:id,name', 'service:id,name']));
    }

    public function destroy(AssociationServicePrice $associationServicePrice): JsonResponse
    {
        $associationServicePrice->delete();
        return response()->json(['message' => 'Deleted']);
    }

    public function byAssociation(Association $association): JsonResponse
    {
        $rows = AssociationServicePrice::with('service:id,name')
            ->where('association_id', $association->id)
            ->orderByDesc('id')
            ->get();
        return response()->json($rows);
    }

    public function byService(Service $service): JsonResponse
    {
        $rows = AssociationServicePrice::with('association:id,name')
            ->where('service_id', $service->id)
            ->orderByDesc('id')
            ->get();
        return response()->json($rows);
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['integer', 'exists:association_service_prices,id']]);
        $skipped = [];
        $deleted = 0;
        foreach ($request->ids as $id) {
            try {
                $deleted += AssociationServicePrice::where('id', $id)->delete();
            } catch (\Illuminate\Database\QueryException $e) {
                $skipped[] = ['id' => $id, 'reason' => $e->getMessage()];
            }
        }
        return response()->json(['message' => 'Bulk delete completed.', 'deleted_count' => $deleted, 'skipped' => $skipped]);
    }

    public function exportExcell()
    {
        $query = AssociationServicePrice::query();
        $collection = $query->get();
        if ($collection->isEmpty()) return response()->json(['message' => 'No rows found.'], 404);
        $columns = ['id','association_id','service_id','price','discount','created_at','updated_at'];
        $headings = ['ID','Association ID','Service ID','Price','Discount','Created At','Updated At'];
        $fileName = 'association_service_prices_' . date('Y-m-d_H-i-s') . '.xlsx';
        return Excel::download(new Export($query, $columns, $headings), $fileName);
    }

    public function exportPdf(ExportPDF $pdfService)
    {
        $rows = AssociationServicePrice::select('id','association_id','service_id','price','discount')->get();
        if ($rows->isEmpty()) return response()->json(['message' => 'No rows found.'], 404);
        $title = 'Association Service Prices';
        $headers = [
            'id' => 'ID',
            'association_id' => 'Association ID',
            'service_id' => 'Service ID',
            'price' => 'Price',
            'discount' => 'Discount',
        ];
        $pdf = $pdfService->generatePdf($title, $headers, $rows->toArray());
        return $pdf->download('AssociationServicePrices.pdf');
    }

    public function importFromExcel(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:xlsx,xls,csv']);
        $import = new DynamicExcelImport(
            AssociationServicePrice::class,
            ['association_id','service_id','price','discount'],
            function ($row) {
                $errors = [];
                if (empty($row['association_id'])) $errors[] = 'Missing association_id';
                if (empty($row['service_id'])) $errors[] = 'Missing service_id';
                if (isset($row['price']) && !is_numeric($row['price'])) $errors[] = 'price must be numeric';
                if (isset($row['discount']) && !is_numeric($row['discount'])) $errors[] = 'discount must be numeric';
                return $errors;
            },
            function ($row) {
                return [
                    'association_id' => (int) $row['association_id'],
                    'service_id' => (int) $row['service_id'],
                    'price' => isset($row['price']) ? (float) $row['price'] : 0,
                    'discount' => isset($row['discount']) ? (float) $row['discount'] : null,
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


