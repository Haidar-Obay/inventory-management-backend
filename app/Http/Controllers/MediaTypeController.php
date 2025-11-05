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
        $data = $request->validated();
        $nextId = $this->computeNextAvailableId(MediaType::class, 'id');
        $row = new MediaType($data);
        $row->id = $nextId;
        $row->save();

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
        // Block deletion if referenced by customers
        if ($media_type->customers()->exists()) {
            $count = $media_type->customers()->count();
            $sampleIds = $media_type->customers()->select('customers.id')->limit(1)->pluck('id');

            return response()->json([
                'status' => false,
                'message' => 'Cannot delete media type. It is referenced by existing customers.',
                'details' => [
                    'customers' => [
                        'count' => $count,
                        'sample_ids' => $sampleIds,
                    ],
                ],
            ], 409);
        }

        // Block deletion if it has children (sub-media types) and include details
        if ($media_type->children()->exists()) {
            $count = $media_type->children()->count();
            $sampleIds = $media_type->children()->select('id')->limit(1)->pluck('id');

            return response()->json([
                'status' => false,
                'message' => 'Cannot delete media type. It is referenced by existing sub-media types.',
                'details' => [
                    'sub_media_types' => [
                        'count' => $count,
                        'sample_ids' => $sampleIds,
                    ],
                ],
            ], 409);
        }

        $media_type->delete();

        return response()->json(['message' => 'Deleted']);
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['integer', 'exists:media_types,id']]);
        $skipped = [];
        $deleted = 0;
        foreach ($request->ids as $id) {
            $mediaType = MediaType::find($id);

            // Check if the media type has any customers linked to it and include details
            if ($mediaType->customers()->exists()) {
                $customersCount = $mediaType->customers()->count();
                $details = [
                    'customers' => [
                        'count' => $customersCount,
                        'sample_ids' => $mediaType->customers()->select('customers.id')->limit(1)->pluck('id'),
                    ],
                ];

                $skipped[] = [
                    'id' => $id,
                    'reason' => 'Cannot delete media type. It is referenced by existing customers.',
                    'details' => $details,
                ];

                continue;
            }

            // Check if the media type has any sub-media types and include details
            if ($mediaType->children()->exists()) {
                $subMediaTypesCount = $mediaType->children()->count();
                $details = [
                    'sub_media_types' => [
                        'count' => $subMediaTypesCount,
                        'sample_ids' => $mediaType->children()->select('media_types.id')->limit(1)->pluck('id'),
                    ],
                ];

                $skipped[] = [
                    'id' => $id,
                    'reason' => 'Cannot delete media type. It is referenced by existing sub-media types.',
                    'details' => $details,
                ];

                continue;
            }

            try {
                $deleted += $mediaType->delete();
            } catch (\Illuminate\Database\QueryException $e) {
                // Check if it's a foreign key constraint error and include details
                if ($e->getCode() == '23503') {
                    $details = [];

                    try {
                        $mediaType = MediaType::find($id);
                        $customersCount = $mediaType?->customers()->count() ?? 0;
                        $subMediaTypesCount = $mediaType?->children()->count() ?? 0;
                        if ($customersCount > 0) {
                            $details['customers'] = [
                                'count' => $customersCount,
                                'sample_ids' => $mediaType->customers()->select('customers.id')->limit(1)->pluck('id'),
                            ];
                        }
                        if ($subMediaTypesCount > 0) {
                            $details['sub_media_types'] = [
                                'count' => $subMediaTypesCount,
                                'sample_ids' => $mediaType->children()->select('media_types.id')->limit(1)->pluck('id'),
                            ];
                        }
                    } catch (\Throwable $ignored) {
                    }

                    $skipped[] = [
                        'id' => $id,
                        'reason' => 'Cannot delete media type. It is referenced by existing customers or sub-media types.',
                        'details' => $details,
                    ];
                } else {
                    $skipped[] = ['id' => $id, 'reason' => $e->getMessage()];
                }
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
