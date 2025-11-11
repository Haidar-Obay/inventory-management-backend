<?php

namespace App\Http\Controllers;

use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Http\Requests\Section\StoreSectionRequest;
use App\Http\Requests\Section\UpdateSectionRequest;
use App\Imports\DynamicExcelImport;
use App\Models\Section;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;

class SectionController extends Controller
{
    public function index()
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_sections";

        $sections = app('cache')->store('database')->get($key);

        if (! $sections) {
            $sections = Section::with(['room:id,name,location'])->ordered()->get();

            app('cache')->store('database')->forever($key, $sections);
        }

        return response()->json([
            'status' => true,
            'message' => 'Sections fetched successfully.',
            'data' => $sections,
        ]);
    }

    public function store(StoreSectionRequest $request)
    {
        $validated = $request->validated();

        $nextId = $this->computeNextAvailableId(Section::class, 'id');
        $section = new Section($validated);
        $section->id = $nextId;
        $section->save();

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_sections");

        return response()->json([
            'status' => true,
            'message' => 'Section created successfully.',
            'data' => $section->load('room:id,name,location'),
        ], 201);
    }

    public function show(Section $section)
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_section_{$section->id}";

        $cachedSection = app('cache')->store('database')->get($key);

        if (! $cachedSection) {
            $cachedSection = $section->load(['room:id,name,location', 'assets:id,name,type,status']);

            app('cache')->store('database')->forever($key, $cachedSection);
        }

        return response()->json([
            'status' => true,
            'message' => 'Section details fetched successfully.',
            'data' => $cachedSection,
        ]);
    }

    public function update(UpdateSectionRequest $request, Section $section)
    {
        $validated = $request->validated();

        // Handle unique validation for name field within the same room
        if (isset($validated['name'])) {
            $validator = Validator::make(['name' => $validated['name']], [
                'name' => 'unique:sections,name,'.$section->id.',id,room_id,'.$section->room_id,
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }
        }

        $section->update($validated);

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_sections");
        app('cache')->store('database')->forget("tenant_{$tenantId}_section_{$section->id}");

        return response()->json([
            'status' => true,
            'message' => 'Section updated successfully.',
            'data' => $section->load('room:id,name,location'),
        ]);
    }

    public function destroy(Section $section)
    {
        $identifier = $section->name ?? "ID: {$section->id}";

        // Check if section has assets
        if ($section->assets()->exists()) {
            $assetsCount = $section->assets()->count();
            $sampleIds = $section->assets()->select('assets.id')->limit(1)->pluck('id');

            return response()->json([
                'status' => false,
                'message' => "Cannot delete section \"{$identifier}\" (ID: {$section->id}). It is referenced by existing assets.",
                'details' => [
                    'assets' => [
                        'count' => $assetsCount,
                        'sample_ids' => $sampleIds,
                    ],
                ],
            ], 409);
        }

        $section->delete();

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_sections");
        app('cache')->store('database')->forget("tenant_{$tenantId}_section_{$section->id}");

        return response()->json([
            'status' => true,
            'message' => 'Section deleted successfully.',
        ]);
    }

    public function bulkDelete(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:sections,id',
        ]);

        $tenantId = tenant('id');
        $skipped = [];
        $deleted = 0;

        foreach ($request->ids as $id) {
            try {
                $section = Section::find($id);

                if (! $section) {
                    $skipped[] = [
                        'id' => $id,
                        'name' => "ID: {$id}",
                        'reason' => 'Section not found.',
                    ];

                    continue;
                }

                $identifier = $section->name ?? "ID: {$id}";

                // Check if section has assets
                if ($section->assets()->exists()) {
                    $assetsCount = $section->assets()->count();
                    $details = [
                        'assets' => [
                            'count' => $assetsCount,
                            'sample_ids' => $section->assets()->select('assets.id')->limit(1)->pluck('id'),
                        ],
                    ];

                    $skipped[] = [
                        'id' => $id,
                        'name' => $identifier,
                        'reason' => 'Cannot delete section. It is referenced by existing assets.',
                        'details' => $details,
                    ];

                    continue;
                }

                $deleted += $section->delete();
                app('cache')->store('database')->forget("tenant_{$tenantId}_section_{$id}");
            } catch (\Illuminate\Database\QueryException $e) {
                $section = Section::find($id);
                $identifier = $section?->name ?? "ID: {$id}";
                $skipped[] = [
                    'id' => $id,
                    'name' => $identifier,
                    'reason' => $e->getMessage(),
                ];
            }
        }

        app('cache')->store('database')->forget("tenant_{$tenantId}_sections");

        return response()->json([
            'status' => true,
            'message' => 'Bulk delete completed.',
            'data' => [
                'deleted_count' => $deleted,
                'skipped' => $skipped,
            ],
        ]);
    }

    public function exportExcell()
    {
        $sections = Section::with('room:id,name')->ordered();
        $collection = $sections->get();

        if ($collection->isEmpty()) {
            return response()->json(['message' => 'No sections found.'], 404);
        }

        $columns = ['id', 'room_id', 'name', 'order_index', 'created_at', 'updated_at'];
        $headings = ['ID', 'Room ID', 'Name', 'Order Index', 'Created At', 'Updated At'];

        return Excel::download(new Export($sections, $columns, $headings), 'sections.xlsx');
    }

    public function exportPdf()
    {
        $sections = Section::with('room:id,name')->select('id', 'name', 'order_index', 'room_id', 'created_at', 'updated_at')->get();

        if ($sections->isEmpty()) {
            return response()->json(['message' => 'No sections found.'], 404);
        }

        $title = 'Section Report';
        $headers = [
            'id' => 'Section ID',
            'name' => 'Section Name',
            'order_index' => 'Order Index',
            'room_id' => 'Room ID',
        ];
        $data = $sections->toArray();

        $pdfService = new ExportPDF;
        $pdf = $pdfService->generatePdf($title, $headers, $data);

        return $pdf->download('Sections.pdf');
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
            Section::truncate();
        }

        // If type is 'mapping', use provided mapping, else use default
        $mapping = $request->input('mapping');

        $import = new DynamicExcelImport(
            Section::class,
            ['room_id', 'name', 'order_index'],
            function ($row) {
                $errors = [];

                if (empty($row['room_id'])) {
                    $errors[] = 'Missing room_id';
                }
                if (empty($row['name'])) {
                    $errors[] = 'Missing name';
                }

                return $errors;
            },
            function ($row) {
                return [
                    'room_id' => $row['room_id'],
                    'name' => $row['name'],
                    'order_index' => $row['order_index'] ?? 0,
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

        app('cache')->store('database')->forget('tenant_'.tenant('id').'_sections');

        return response()->json([
            'status' => true,
            'message' => 'Import completed successfully.',
            'data' => [
                'rows_imported' => $import->getImportedCount(),
                'rows_skipped_count' => $import->getSkippedCount(),
                'skipped_rows' => $import->getSkippedRows(),
            ],
        ]);
    }

    public function byRoom($roomId)
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_room_{$roomId}_sections";

        $sections = app('cache')->store('database')->get($key);

        if (! $sections) {
            $sections = Section::with(['room:id,name,location'])
                ->byRoom($roomId)
                ->ordered()
                ->get();

            app('cache')->store('database')->forever($key, $sections);
        }

        return response()->json([
            'status' => true,
            'message' => 'Sections for room fetched successfully.',
            'data' => $sections,
        ]);
    }
}
