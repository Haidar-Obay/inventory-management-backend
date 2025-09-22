<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreReferrerServiceCommissionRequest;
use App\Http\Requests\UpdateReferrerServiceCommissionRequest;
use App\Models\Referrer;
use App\Models\ReferrerServiceCommission;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Imports\DynamicExcelImport;

class ReferrerServiceCommissionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ReferrerServiceCommission::query()->with(['referrer:id,name', 'service:id,name']);
        if ($request->filled('referrer_id')) $query->where('referrer_id', $request->integer('referrer_id'));
        if ($request->filled('service_id')) $query->where('service_id', $request->integer('service_id'));
        return response()->json($query->orderByDesc('id')->paginate());
    }

    public function store(StoreReferrerServiceCommissionRequest $request): JsonResponse
    {
        $row = ReferrerServiceCommission::create($request->validated());
        return response()->json($row->load(['referrer:id,name', 'service:id,name']), 201);
    }

    public function show(ReferrerServiceCommission $referrerServiceCommission): JsonResponse
    {
        return response()->json($referrerServiceCommission->load(['referrer:id,name', 'service:id,name']));
    }

    public function update(UpdateReferrerServiceCommissionRequest $request, ReferrerServiceCommission $referrerServiceCommission): JsonResponse
    {
        $referrerServiceCommission->update($request->validated());
        return response()->json($referrerServiceCommission->load(['referrer:id,name', 'service:id,name']));
    }

    public function destroy(ReferrerServiceCommission $referrerServiceCommission): JsonResponse
    {
        $referrerServiceCommission->delete();
        return response()->json(['message' => 'Deleted']);
    }

    public function byReferrer(Referrer $referrer): JsonResponse
    {
        $rows = ReferrerServiceCommission::with('service:id,name')
            ->where('referrer_id', $referrer->id)
            ->orderByDesc('id')
            ->get();
        return response()->json($rows);
    }

    public function byService(Service $service): JsonResponse
    {
        $rows = ReferrerServiceCommission::with('referrer:id,name')
            ->where('service_id', $service->id)
            ->orderByDesc('id')
            ->get();
        return response()->json($rows);
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['integer', 'exists:referrer_service_commissions,id']]);
        $skipped = [];
        $deleted = 0;
        foreach ($request->ids as $id) {
            try {
                $deleted += ReferrerServiceCommission::where('id', $id)->delete();
            } catch (\Illuminate\Database\QueryException $e) {
                $skipped[] = ['id' => $id, 'reason' => $e->getMessage()];
            }
        }
        return response()->json(['message' => 'Bulk delete completed.', 'deleted_count' => $deleted, 'skipped' => $skipped]);
    }

    public function exportExcell()
    {
        $query = ReferrerServiceCommission::query();
        $collection = $query->get();
        if ($collection->isEmpty()) return response()->json(['message' => 'No rows found.'], 404);
        $columns = ['id','referrer_id','service_id','price_override','discount_override','commission_percent','created_at','updated_at'];
        $headings = ['ID','Referrer ID','Service ID','Price Override','Discount Override','Commission %','Created At','Updated At'];
        $fileName = 'referrer_service_commissions_' . date('Y-m-d_H-i-s') . '.xlsx';
        return Excel::download(new Export($query, $columns, $headings), $fileName);
    }

    public function exportPdf(ExportPDF $pdfService)
    {
        $rows = ReferrerServiceCommission::select('id','referrer_id','service_id','price_override','discount_override','commission_percent')->get();
        if ($rows->isEmpty()) return response()->json(['message' => 'No rows found.'], 404);
        $title = 'Referrer Service Commissions';
        $headers = [
            'id' => 'ID',
            'referrer_id' => 'Referrer ID',
            'service_id' => 'Service ID',
            'price_override' => 'Price Override',
            'discount_override' => 'Discount Override',
            'commission_percent' => 'Commission %',
        ];
        $pdf = $pdfService->generatePdf($title, $headers, $rows->toArray());
        return $pdf->download('ReferrerServiceCommissions.pdf');
    }

    public function importFromExcel(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:xlsx,xls,csv']);
        $import = new DynamicExcelImport(
            ReferrerServiceCommission::class,
            ['referrer_id','service_id','price_override','discount_override','commission_percent'],
            function ($row) {
                $errors = [];
                if (empty($row['referrer_id'])) $errors[] = 'Missing referrer_id';
                if (empty($row['service_id'])) $errors[] = 'Missing service_id';
                if (isset($row['price_override']) && !is_numeric($row['price_override'])) $errors[] = 'price_override must be numeric';
                if (isset($row['discount_override']) && !is_numeric($row['discount_override'])) $errors[] = 'discount_override must be numeric';
                if (isset($row['commission_percent']) && !is_numeric($row['commission_percent'])) $errors[] = 'commission_percent must be numeric';
                return $errors;
            },
            function ($row) {
                return [
                    'referrer_id' => (int) $row['referrer_id'],
                    'service_id' => (int) $row['service_id'],
                    'price_override' => isset($row['price_override']) ? (float) $row['price_override'] : null,
                    'discount_override' => isset($row['discount_override']) ? (float) $row['discount_override'] : null,
                    'commission_percent' => isset($row['commission_percent']) ? (float) $row['commission_percent'] : null,
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


