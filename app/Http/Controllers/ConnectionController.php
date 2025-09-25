<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreConnectionRequest;
use App\Http\Requests\UpdateConnectionRequest;
use App\Models\Connection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Imports\DynamicExcelImport;

class ConnectionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Connection::query()->with('type:id,name');
        if ($request->filled('type_id')) $query->where('type_id', $request->integer('type_id'));
        return response()->json($query->orderBy('name')->paginate());
    }

    public function store(StoreConnectionRequest $request): JsonResponse
    {
        $row = Connection::create($request->validated());
        return response()->json($row->load('type:id,name'), 201);
    }

    public function show(Connection $connection): JsonResponse
    {
        return response()->json($connection->load('type:id,name'));
    }

    public function update(UpdateConnectionRequest $request, Connection $connection): JsonResponse
    {
        $connection->update($request->validated());
        return response()->json($connection->load('type:id,name'));
    }

    public function destroy(Connection $connection): JsonResponse
    {
        $connection->delete();
        return response()->json(['message' => 'Deleted']);
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['integer', 'exists:connections,id']]);
        $skipped = [];
        $deleted = 0;
        foreach ($request->ids as $id) {
            try {
                $deleted += Connection::where('id', $id)->delete();
            } catch (\Illuminate\Database\QueryException $e) {
                $skipped[] = ['id' => $id, 'reason' => $e->getMessage()];
            }
        }
        return response()->json(['message' => 'Bulk delete completed.', 'deleted_count' => $deleted, 'skipped' => $skipped]);
    }

    public function exportExcell()
    {
        $query = Connection::query();
        $collection = $query->get();
        if ($collection->isEmpty()) return response()->json(['message' => 'No connections found.'], 404);
        $columns = ['id','name','type_id','created_at','updated_at'];
        $headings = ['ID','Name','Type ID','Created At','Updated At'];
        $fileName = 'connections_' . date('Y-m-d_H-i-s') . '.xlsx';
        return Excel::download(new Export($query, $columns, $headings), $fileName);
    }

    public function exportPdf(ExportPDF $pdfService)
    {
        $rows = Connection::select('id','name','type_id')->get();
        if ($rows->isEmpty()) return response()->json(['message' => 'No connections found.'], 404);
        $title = 'Connections';
        $headers = [
            'id' => 'ID',
            'name' => 'Name',
            'type_id' => 'Type ID',
        ];
        $pdf = $pdfService->generatePdf($title, $headers, $rows->toArray());
        return $pdf->download('Connections.pdf');
    }

    public function importFromExcel(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:xlsx,xls,csv']);
        $import = new DynamicExcelImport(
            Connection::class,
            ['name','type_id'],
            function ($row) {
                $errors = [];
                if (empty($row['name'])) $errors[] = 'Missing name';
                if (empty($row['type_id'])) $errors[] = 'Missing type_id';
                return $errors;
            },
            function ($row) {
                return [
                    'name' => $row['name'],
                    'type_id' => (int) $row['type_id'],
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


