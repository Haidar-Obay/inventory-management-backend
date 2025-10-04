<?php

namespace App\Http\Controllers;

use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Http\Requests\StoreConnectionTypeRequest;
use App\Http\Requests\UpdateConnectionTypeRequest;
use App\Imports\DynamicExcelImport;
use App\Models\ConnectionType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class ConnectionTypeController extends Controller
{
    public function index(): JsonResponse
    {
        $rows = ConnectionType::orderBy('name')->paginate();

        return response()->json($rows);
    }

    public function store(StoreConnectionTypeRequest $request): JsonResponse
    {
        $row = ConnectionType::create($request->validated());

        return response()->json($row, 201);
    }

    public function show(ConnectionType $connection_type): JsonResponse
    {
        return response()->json($connection_type);
    }

    public function update(UpdateConnectionTypeRequest $request, ConnectionType $connection_type): JsonResponse
    {
        $connection_type->update($request->validated());

        return response()->json($connection_type);
    }

    public function destroy(ConnectionType $connection_type): JsonResponse
    {
        $connection_type->delete();

        return response()->json(['message' => 'Deleted']);
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['integer', 'exists:connection_types,id']]);
        $skipped = [];
        $deleted = 0;
        foreach ($request->ids as $id) {
            try {
                $deleted += ConnectionType::where('id', $id)->delete();
            } catch (\Illuminate\Database\QueryException $e) {
                $skipped[] = ['id' => $id, 'reason' => $e->getMessage()];
            }
        }

        return response()->json(['message' => 'Bulk delete completed.', 'deleted_count' => $deleted, 'skipped' => $skipped]);
    }

    public function exportExcell()
    {
        $query = ConnectionType::query();
        $collection = $query->get();
        if ($collection->isEmpty()) {
            return response()->json(['message' => 'No connection types found.'], 404);
        }
        $columns = ['id', 'name', 'created_at', 'updated_at', 'created_at', 'updated_at'];
        $headings = ['ID', 'Name', 'Created At', 'Updated At', 'Created At', 'Updated At'];
        $fileName = 'connection_types'.'.xlsx';

        return Excel::download(new Export($query, $columns, $headings), $fileName);
    }

    public function exportPdf(ExportPDF $pdfService)
    {
        $rows = ConnectionType::select('id', 'name')->get();
        if ($rows->isEmpty()) {
            return response()->json(['message' => 'No connection types found.'], 404);
        }
        $title = 'Connection Types';
        $headers = ['id' => 'ID', 'name' => 'Name', 'created_at' => 'Created At', 'updated_at' => 'Updated At', 'created_at' => 'Created At', 'updated_at' => 'Updated At', 'created_at' => 'Created At', 'updated_at' => 'Updated At'];
        $pdf = $pdfService->generatePdf($title, $headers, $rows->toArray());

        return $pdf->download('ConnectionTypes.pdf');
    }

    public function importFromExcel(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:xlsx,xls,csv']);
        $import = new DynamicExcelImport(
            ConnectionType::class,
            ['name'],
            function ($row) {
                $errors = [];
                if (empty($row['name'])) {
                    $errors[] = 'Missing name';
                }

                return $errors;
            },
            function ($row) {
                return ['name' => $row['name']];
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
