<?php

namespace App\Http\Controllers;

use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Http\Requests\StoreAssociationRequest;
use App\Http\Requests\UpdateAssociationRequest;
use App\Imports\DynamicExcelImport;
use App\Models\Association;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class AssociationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Association::query()->with('contacts');
        if ($request->filled('active')) {
            $query->where('active', filter_var($request->input('active'), FILTER_VALIDATE_BOOLEAN));
        }

        return response()->json($query->orderBy('name')->paginate());
    }

    public function getNames(): JsonResponse
    {
        $associations = Association::where('active', true)
            ->select('id', 'name')
            ->orderBy('name')
            ->get();

        return response()->json([
            'status' => true,
            'data' => $associations,
        ]);
    }

    public function store(StoreAssociationRequest $request): JsonResponse
    {
        $nextId = $this->computeNextAvailableId(Association::class, 'id');
        $association = new Association($request->validated());
        $association->id = $nextId;
        $association->save();
        $association->load('contacts');

        return response()->json([
            'status' => true,
            'message' => 'Association created successfully.',
            'data' => $association,
        ], 201);
    }

    public function show(Association $association): JsonResponse
    {
        $association->load('contacts');

        return response()->json([
            'status' => true,
            'message' => 'Association details fetched successfully.',
            'data' => $association,
        ]);
    }

    public function update(UpdateAssociationRequest $request, Association $association): JsonResponse
    {
        $association->update($request->validated());
        $association->load('contacts');

        return response()->json([
            'status' => true,
            'message' => 'Association updated successfully.',
            'data' => $association,
        ]);
    }

    public function destroy(Association $association): JsonResponse
    {
        $association->delete();

        return response()->json(['message' => 'Deleted']);
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer', 'exists:associations,id'],
        ]);
        $skipped = [];
        $deleted = 0;
        foreach ($request->ids as $id) {
            try {
                $deleted += Association::where('id', $id)->delete();
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
        $query = Association::query();
        $collection = $query->get();
        if ($collection->isEmpty()) {
            return response()->json(['message' => 'No associations found.'], 404);
        }

        $columns = [
            'id', 'name', 'phone1', 'phone2', 'email', 'website', 'markup_value', 'markup_type', 'markdown_value', 'markdown_type', 'allowed_to_pay_for_guests', 'active', 'created_at', 'updated_at',
        ];
        $headings = [
            'ID', 'Name', 'Phone 1', 'Phone 2', 'Email', 'Website', 'Markup Value', 'Markup Type', 'Markdown Value', 'Markdown Type', 'Allowed To Pay For Guests', 'Active', 'Created At', 'Updated At',
        ];
        $fileName = 'associations_'.'.xlsx';

        return Excel::download(new Export($query, $columns, $headings), $fileName);
    }

    public function exportPdf(ExportPDF $pdfService)
    {
        $rows = Association::select('id', 'name', 'phone1', 'phone2', 'email', 'website', 'allowed_to_pay_for_guests', 'active')->get();
        if ($rows->isEmpty()) {
            return response()->json(['message' => 'No associations found.'], 404);
        }
        $title = 'Associations';
        $headers = [
            'id' => 'ID',
            'name' => 'Name',
            'phone1' => 'Phone 1',
            'phone2' => 'Phone 2',
            'email' => 'Email',
            'website' => 'Website',
            'allowed_to_pay_for_guests' => 'Allowed Guests',
            'active' => 'Active',
            'created_at' => 'Created At',
            'updated_at' => 'Updated At'];
        $pdf = $pdfService->generatePdf($title, $headers, $rows->toArray());

        return $pdf->download('Associations.pdf');
    }

    public function importFromExcel(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:xlsx,xls,csv']);
        $import = new DynamicExcelImport(
            Association::class,
            ['name', 'phone1', 'phone2', 'email', 'website', 'markup_value', 'markup_type', 'markdown_value', 'markdown_type', 'allowed_to_pay_for_guests', 'active'],
            function ($row) {
                $errors = [];
                if (empty($row['name'])) {
                    $errors[] = 'Missing name';
                }
                if (! empty($row['markup_type']) && ! in_array(strtolower($row['markup_type']), ['percent', 'amount'])) {
                    $errors[] = 'Invalid markup_type';
                }
                if (! empty($row['markdown_type']) && ! in_array(strtolower($row['markdown_type']), ['percent', 'amount'])) {
                    $errors[] = 'Invalid markdown_type';
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
                    'phone1' => $row['phone1'] ?? null,
                    'phone2' => $row['phone2'] ?? null,
                    'email' => $row['email'] ?? null,
                    'website' => $row['website'] ?? null,
                    'markup_value' => isset($row['markup_value']) ? (float) $row['markup_value'] : null,
                    'markup_type' => $row['markup_type'] ?? null,
                    'markdown_value' => isset($row['markdown_value']) ? (float) $row['markdown_value'] : null,
                    'markdown_type' => $row['markdown_type'] ?? null,
                    'allowed_to_pay_for_guests' => $toBool($row['allowed_to_pay_for_guests'] ?? false),
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
}
