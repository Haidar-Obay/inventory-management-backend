<?php

namespace App\Http\Controllers;

use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Http\Requests\ReferBy\StoreReferByRequest;
use App\Http\Requests\ReferBy\UpdateReferByRequest;
use App\Imports\DynamicExcelImport;
use App\Models\ReferBy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class ReferByController extends Controller
{
    public function index(): JsonResponse
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_refer_bies";

        $referBies = app('cache')->store('database')->get($key);

        if (! $referBies) {
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
        $data = $request->validated();
        $nextId = $this->computeNextAvailableId(ReferBy::class, 'id');
        $referBy = new ReferBy($data);
        $referBy->id = $nextId;
        $referBy->save();

        app('cache')->store('database')->forget('tenant_'.tenant('id').'_refer_bies');

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

        if (! $cached) {
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
        $identifier = $referBy->name ?? "ID: {$referBy->id}";
        
        // Check if refer by has customers
        if ($referBy->customers()->exists()) {
            $customersCount = $referBy->customers()->count();
            $sampleIds = $referBy->customers()->select('customers.id')->limit(1)->pluck('id');
            
            return response()->json([
                'status' => false,
                'message' => "Cannot delete refer by \"{$identifier}\" (ID: {$referBy->id}). It is referenced by existing customers.",
                'details' => [
                    'customers' => [
                        'count' => $customersCount,
                        'sample_ids' => $sampleIds,
                    ],
                ],
            ], 409);
        }

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
                $referBy = ReferBy::find($id);
                
                if (!$referBy) {
                    $skipped[] = [
                        'id' => $id,
                        'name' => "ID: {$id}",
                        'reason' => 'Refer By not found.',
                    ];
                    continue;
                }
                
                $identifier = $referBy->name ?? "ID: {$id}";
                
                // Check if refer by has customers
                if ($referBy->customers()->exists()) {
                    $customersCount = $referBy->customers()->count();
                    $details = [
                        'customers' => [
                            'count' => $customersCount,
                            'sample_ids' => $referBy->customers()->select('customers.id')->limit(1)->pluck('id'),
                        ],
                    ];
                    
                    $skipped[] = [
                        'id' => $id,
                        'name' => $identifier,
                        'reason' => 'Cannot delete refer by. It is referenced by existing customers.',
                        'details' => $details,
                    ];
                    continue;
                }
                
                $deleted += $referBy->delete();
                app('cache')->store('database')->forget("tenant_{$tenantId}_refer_by_show_{$id}");
            } catch (\Illuminate\Database\QueryException $e) {
                $referBy = ReferBy::find($id);
                $identifier = $referBy?->name ?? "ID: {$id}";
                $skipped[] = [
                    'id' => $id,
                    'name' => $identifier,
                    'reason' => $e->getMessage(),
                ];
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
        $columns = ['id', 'name', 'created_at', 'updated_at', 'created_at', 'updated_at'];
        $headings = ['ID', 'Name', 'Created At', 'Updated At', 'Created At', 'Updated At'];

        return Excel::download(new Export($ReferBy, $columns, $headings), 'ReferBy.xlsx');
    }

    public function exportPdf(ExportPDF $pdfService)
    {
        $referBies = ReferBy::select('id', 'name')->get();

        if ($referBies->isEmpty()) {
            return response()->json(['message' => 'No refer bies found.'], 404);
        }

        $title = 'Refer By Group Report';
        $headers = ['id' => 'Refer By ID', 'name' => 'Refer By Name', 'created_at' => 'Created At', 'updated_at' => 'Updated At'];
        $data = $referBies->toArray();

        $pdf = $pdfService->generatePdf($title, $headers, $data);

        return $pdf->download('ReferByReport.pdf');
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

        // If type is 'fresh', delete all records first
        if ($request->input('type') === 'fresh') {
            // Get model class from the import
            ReferBy::truncate();
        }

        // If type is 'mapping', use provided mapping, else use default
        $mapping = $request->input('mapping');

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
                    if (! empty($row[$phoneField]) && ! preg_match('/^\d+$/', $row[$phoneField])) {
                        $errors[] = "$phoneField must contain only numbers";
                    }
                }

                if (! empty($row['email']) && ! filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
                    $errors[] = 'Invalid email format';
                }

                if (! empty($row['fix_commission']) && ! is_numeric($row['fix_commission'])) {
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
            },
            true // Enable header validation
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

        app('cache')->store('database')->forget('tenant_'.tenant('id').'_refer_bies');

        return response()->json([
            'success' => true,
            'rows_imported' => $import->getImportedCount(),
            'rows_skipped_count' => $import->getSkippedCount(),
            'skipped_rows' => $import->getSkippedRows(),
        ]);
    }
}
