<?php

namespace App\Http\Controllers;

use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Http\Requests\StoreMediaTypeRequest;
use App\Http\Requests\UpdateMediaTypeRequest;
use App\Imports\DynamicExcelImport;
use App\Models\MediaType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class MediaTypeController extends Controller
{
    public function index(): JsonResponse
    {
        $rows = MediaType::with(['parent', 'children'])
            ->orderBy('name')
            ->paginate();

        return response()->json($rows);
    }

    public function store(StoreMediaTypeRequest $request): JsonResponse
    {
        $row = MediaType::create($request->validated());

        return response()->json($row, 201);
    }

    public function show(MediaType $media_type): JsonResponse
    {
        $media_type->load(['parent', 'children']);
        return response()->json($media_type);
    }

    public function update(UpdateMediaTypeRequest $request, MediaType $media_type): JsonResponse
    {
        $media_type->update($request->validated());

        return response()->json($media_type);
    }

    public function destroy(MediaType $media_type): JsonResponse
    {
        $media_type->delete();

        return response()->json(['message' => 'Deleted']);
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['integer', 'exists:media_types,id']]);
        $skipped = [];
        $deleted = 0;
        foreach ($request->ids as $id) {
            try {
                $deleted += MediaType::where('id', $id)->delete();
            } catch (\Illuminate\Database\QueryException $e) {
                $skipped[] = ['id' => $id, 'reason' => $e->getMessage()];
            }
        }

        return response()->json(['message' => 'Bulk delete completed.', 'deleted_count' => $deleted, 'skipped' => $skipped]);
    }

    public function exportExcell()
    {
        $query = MediaType::query();
        $collection = $query->get();
        if ($collection->isEmpty()) {
            return response()->json(['message' => 'No media types found.'], 404);
        }
        $columns = ['id', 'name', 'created_at', 'updated_at', 'created_at', 'updated_at'];
        $headings = ['ID', 'Name', 'Created At', 'Updated At', 'Created At', 'Updated At'];
        $fileName = 'media_types'.'.xlsx';

        return Excel::download(new Export($query, $columns, $headings), $fileName);
    }

    public function exportPdf(ExportPDF $pdfService)
    {
        $rows = MediaType::select('id', 'name')->get();
        if ($rows->isEmpty()) {
            return response()->json(['message' => 'No media types found.'], 404);
        }
        $title = 'Media Types';
        $headers = ['id' => 'ID', 'name' => 'Name', 'created_at' => 'Created At', 'updated_at' => 'Updated At', 'created_at' => 'Created At', 'updated_at' => 'Updated At', 'created_at' => 'Created At', 'updated_at' => 'Updated At'];
        $pdf = $pdfService->generatePdf($title, $headers, $rows->toArray());

        return $pdf->download('MediaTypes.pdf');
    }

    public function importFromExcel(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:xlsx,xls,csv']);
        $import = new DynamicExcelImport(
            MediaType::class,
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

    public function getParentMediaTypes(): JsonResponse
    {
        $parentMediaTypes = MediaType::whereNull('sub_media_type_of')
            ->orderBy('name')
            ->get();

        return response()->json($parentMediaTypes);
    }

    public function getSubMediaTypes(MediaType $media_type): JsonResponse
    {
        $subMediaTypes = $media_type->children()->orderBy('name')->get();
        return response()->json($subMediaTypes);
    }

    public function getHierarchy(): JsonResponse
    {
        $hierarchy = MediaType::whereNull('sub_media_type_of')
            ->with('getAllChildren')
            ->orderBy('name')
            ->get();

        return response()->json($hierarchy);
    }
}
