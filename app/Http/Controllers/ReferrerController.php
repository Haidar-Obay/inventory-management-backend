<?php

namespace App\Http\Controllers;

use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Http\Requests\StoreReferrerRequest;
use App\Http\Requests\UpdateReferrerRequest;
use App\Imports\DynamicExcelImport;
use App\Models\Referrer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class ReferrerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Referrer::query();
        if ($request->filled('active')) {
            $query->where('active', filter_var($request->input('active'), FILTER_VALIDATE_BOOLEAN));
        }

        return response()->json($query->orderBy('name')->paginate());
    }

    public function getNames(): JsonResponse
    {
        $referrers = Referrer::where('active', true)
            ->select('id', 'name')
            ->orderBy('name')
            ->get();

        return response()->json([
            'status' => true,
            'data' => $referrers,
        ]);
    }

    public function store(StoreReferrerRequest $request): JsonResponse
    {
        $data = $request->validated();
        $nextId = $this->computeNextAvailableId(Referrer::class, 'id');
        $row = new Referrer($data);
        $row->id = $nextId;
        $row->save();

        return response()->json($row, 201);
    }

    public function show(Referrer $referrer): JsonResponse
    {
        return response()->json($referrer);
    }

    public function update(UpdateReferrerRequest $request, Referrer $referrer): JsonResponse
    {
        $referrer->update($request->validated());

        return response()->json($referrer);
    }

    public function destroy(Referrer $referrer): JsonResponse
    {
        // Block deletion if referenced by customers
        if ($referrer->customers()->exists()) {
            $count = $referrer->customers()->count();
            $sampleIds = $referrer->customers()->select('customers.id')->limit(1)->pluck('id');

            $identifier = $referrer->name ?? "ID: {$referrer->id}";

            return response()->json([
                'status' => false,
                'message' => "Cannot delete referrer \"{$identifier}\" (ID: {$referrer->id}). It is referenced by existing customers.",
                'details' => [
                    'customers' => [
                        'count' => $count,
                        'sample_ids' => $sampleIds,
                    ],
                ],
            ], 409);
        }

        $referrer->delete();

        return response()->json(['message' => 'Deleted']);
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['integer', 'exists:referrers,id']]);
        $skipped = [];
        $deleted = 0;
        foreach ($request->ids as $id) {
            $referrer = Referrer::find($id);

            if (! $referrer) {
                $skipped[] = [
                    'id' => $id,
                    'name' => "ID: {$id}",
                    'reason' => 'Referrer not found.',
                ];

                continue;
            }

            // Check if the referrer has any customers linked to it and include details
            if ($referrer->customers()->exists()) {
                $customersCount = $referrer->customers()->count();
                $details = [
                    'customers' => [
                        'count' => $customersCount,
                        'sample_ids' => $referrer->customers()->select('customers.id')->limit(1)->pluck('id'),
                    ],
                ];

                $identifier = $referrer->name ?? "ID: {$id}";
                $skipped[] = [
                    'id' => $id,
                    'name' => $identifier,
                    'reason' => 'Cannot delete referrer. It is referenced by existing customers.',
                    'details' => $details,
                ];

                continue;
            }

            try {
                $deleted += $referrer->delete();
            } catch (\Illuminate\Database\QueryException $e) {
                // Check if it's a foreign key constraint error and include details
                if ($e->getCode() == '23503') {
                    $details = [];

                    try {
                        $customersCount = $referrer?->customers()->count() ?? 0;
                        if ($customersCount > 0) {
                            $details['customers'] = [
                                'count' => $customersCount,
                                'sample_ids' => $referrer->customers()->select('customers.id')->limit(1)->pluck('id'),
                            ];
                        }
                    } catch (\Throwable $ignored) {
                    }

                    $referrer = Referrer::find($id);
                    $identifier = $referrer?->name ?? "ID: {$id}";
                    $skipped[] = [
                        'id' => $id,
                        'name' => $identifier,
                        'reason' => 'Cannot delete referrer. It is referenced by existing customers.',
                        'details' => $details,
                    ];
                } else {
                    $referrer = Referrer::find($id);
                    $identifier = $referrer?->name ?? "ID: {$id}";
                    $skipped[] = [
                        'id' => $id,
                        'name' => $identifier,
                        'reason' => $e->getMessage(),
                    ];
                }
            }
        }

        return response()->json(['message' => 'Bulk delete completed.', 'deleted_count' => $deleted, 'skipped' => $skipped]);
    }

    public function exportExcell()
    {
        $query = Referrer::query();
        $collection = $query->get();
        if ($collection->isEmpty()) {
            return response()->json(['message' => 'No referrers found.'], 404);
        }
        $columns = ['id', 'name', 'address', 'phone1', 'phone2', 'email', 'active', 'commission_percent', 'created_at', 'updated_at'];
        $headings = ['ID', 'Name', 'Address', 'Phone 1', 'Phone 2', 'Email', 'Active', 'Commission %', 'Created At', 'Updated At'];
        $fileName = 'referrers'.'.xlsx';

        return Excel::download(new Export($query, $columns, $headings), $fileName);
    }

    public function exportPdf(ExportPDF $pdfService)
    {
        $rows = Referrer::select('id', 'name', 'address', 'phone1', 'phone2', 'email', 'active', 'commission_percent')->get();
        if ($rows->isEmpty()) {
            return response()->json(['message' => 'No referrers found.'], 404);
        }
        $title = 'Referrers';
        $headers = [
            'id' => 'ID',
            'name' => 'Name',
            'address' => 'Address',
            'phone1' => 'Phone 1',
            'phone2' => 'Phone 2',
            'email' => 'Email',
            'active' => 'Active',
            'commission_percent' => 'Commission %',
            'created_at' => 'Created At',
            'updated_at' => 'Updated At'];
        $pdf = $pdfService->generatePdf($title, $headers, $rows->toArray());

        return $pdf->download('Referrers.pdf');
    }

    public function importFromExcel(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:xlsx,xls,csv']);
        $import = new DynamicExcelImport(
            Referrer::class,
            ['name', 'address', 'phone1', 'phone2', 'email', 'active', 'commission_percent'],
            function ($row) {
                $errors = [];
                if (empty($row['name'])) {
                    $errors[] = 'Missing name';
                }
                if (isset($row['commission_percent']) && ! is_numeric($row['commission_percent'])) {
                    $errors[] = 'commission_percent must be numeric';
                }

                return $errors;
            },
            function ($row) {
                $toBool = function ($val) {
                    if (is_bool($val)) {
                        return $val;
                    }
                    $val = strtolower((string) $val);

                    return in_array($val, ['1', 'true', 'yes', 'y']);
                };

                return [
                    'name' => $row['name'],
                    'address' => $row['address'] ?? null,
                    'phone1' => $row['phone1'] ?? null,
                    'phone2' => $row['phone2'] ?? null,
                    'email' => $row['email'] ?? null,
                    'active' => $toBool($row['active'] ?? true),
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
