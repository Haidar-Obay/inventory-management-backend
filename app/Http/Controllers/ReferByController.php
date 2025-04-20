<?php

namespace App\Http\Controllers;

use App\Models\ReferBy;
use App\Http\Requests\ReferBy\StoreReferByRequest;
use App\Http\Requests\ReferBy\UpdateReferByRequest;
use Illuminate\Http\JsonResponse;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Imports\DynamicExcelImport;
use Illuminate\Http\Request;

class ReferByController extends Controller
{
    public function index(): JsonResponse
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_refer_bies";

        $referBies = app('cache')->store('database')->get($key);

        if (!$referBies) {
            $referBies = ReferBy::paginate(10);
            app('cache')->store('database')->forever($key, $referBies);
        }

        return response()->json([
            'status' => true,
            'message' => 'Refer Bies fetched successfully.',
            'data' => $referBies,
        ]);
    }

    public function store(StoreReferByRequest $request): JsonResponse
    {
        $referBy = ReferBy::create($request->validated());

        app('cache')->store('database')->forget("tenant_" . tenant('id') . "_refer_bies");

        return response()->json([
            'message' => 'Refer By created successfully.',
            'data' => $referBy,
        ], 201);
    }

    public function show(ReferBy $referBy): JsonResponse
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_refer_by_show_{$referBy->id}";

        $cached = app('cache')->store('database')->get($key);

        if (!$cached) {
            $cached = $referBy;
            app('cache')->store('database')->forever($key, $cached);
        }

        return response()->json([
            'status' => true,
            'message' => 'Refer By details fetched successfully.',
            'data' => $cached,
        ]);
    }

    public function update(UpdateReferByRequest $request, ReferBy $referBy): JsonResponse
    {
        $referBy->update($request->validated());

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_refer_bies");
        app('cache')->store('database')->forget("tenant_{$tenantId}_refer_by_show_{$referBy->id}");

        return response()->json([
            'status' => true,
            'message' => 'Refer By updated successfully.',
            'data' => $referBy,
        ]);
    }

    public function destroy(ReferBy $referBy): JsonResponse
    {
        $referBy->delete();

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_refer_bies");
        app('cache')->store('database')->forget("tenant_{$tenantId}_refer_by_show_{$referBy->id}");

        return response()->json([
            'status' => true,
            'message' => 'Refer By deleted successfully.',
        ]);
    }

    public function bulkDelete(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:refer_bies,id',
        ]);

        $skipped = [];
        $deleted = 0;
        $tenantId = tenant('id');

        foreach ($request->ids as $id) {
            try {
                $deleted += ReferBy::where('id', $id)->delete();
                app('cache')->store('database')->forget("tenant_{$tenantId}_refer_by_show_{$id}");
            } catch (\Illuminate\Database\QueryException $e) {
                $skipped[] = ['id' => $id, 'reason' => $e->getMessage()];
            }
        }

        app('cache')->store('database')->forget("tenant_{$tenantId}_refer_bies");

        return response()->json([
            'message' => 'Bulk delete completed.',
            'deleted_count' => $deleted,
            'skipped' => $skipped,
        ]);
    }

    public function exportExcell()
    {
        $ReferBy = ReferBy::query();
        $collection = $ReferBy->get();
        if ($collection->isEmpty()) {
            return response()->json(['message' => 'No ReferBy found.'], 404);
        }
        $columns = ['id', 'name'];
        $headings = ['ID', 'Name'];

        return Excel::download(new Export($ReferBy, $columns, $headings), 'ReferBy.xlsx');
    }

    public function exportPdf(ExportPDF $pdfService)
    {
        $referBies = ReferBy::select('id', 'name')->get();

        if ($referBies->isEmpty()) {
            return response()->json(['message' => 'No refer bies found.'], 404);
        }

        $title = 'Refer By Group Report';
        $headers = [
            'id' => 'Refer By ID',
            'name' => 'Refer By Name'
        ];
        $data = $referBies->toArray();

        $pdf = $pdfService->generatePdf($title, $headers, $data);
        return $pdf->download('ReferByReport.pdf');
    }

    public function importFromExcel(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv',
        ]);

        $import = new DynamicExcelImport(
            ReferBy::class,
            ['name', 'address', 'phone1', 'phone2', 'email', 'fix_commission'],
            function ($row) {
                $errors = [];

                if (empty($row['name'])) {
                    $errors[] = 'Missing name';
                } elseif (preg_match('/\d/', $row['name'])) {
                    $errors[] = 'Name should not contain numbers';
                }

                foreach (['phone1', 'phone2'] as $phoneField) {
                    if (!empty($row[$phoneField]) && !preg_match('/^\d+$/', $row[$phoneField])) {
                        $errors[] = "$phoneField must contain only numbers";
                    }
                }

                if (!empty($row['email']) && !filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
                    $errors[] = 'Invalid email format';
                }

                if (!empty($row['fix_commission']) && !is_numeric($row['fix_commission'])) {
                    $errors[] = 'Fix commission must be numeric';
                }

                return $errors;
            },
            function ($row) {
                return [
                    'name' => $row['name'],
                    'address' => $row['address'] ?? null,
                    'phone1' => $row['phone1'] ?? null,
                    'phone2' => $row['phone2'] ?? null,
                    'email' => $row['email'] ?? null,
                    'fix_commission' => $row['fix_commission'] ?? null,
                ];
            }
        );

        Excel::import($import, $request->file('file'));

        app('cache')->store('database')->forget("tenant_" . tenant('id') . "_refer_bies");

        return response()->json([
            'success' => true,
            'rows_imported' => $import->getImportedCount(),
            'rows_skipped_count' => $import->getSkippedCount(),
            'skipped_rows' => $import->getSkippedRows(),
        ]);
    }
}
