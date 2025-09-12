<?php

namespace App\Http\Controllers;

use App\Models\Section;
use App\Http\Requests\Section\StoreSectionRequest;
use App\Http\Requests\Section\UpdateSectionRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

use Maatwebsite\Excel\Facades\Excel;
use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Imports\DynamicExcelImport;

class SectionController extends Controller
{
    public function index()
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_sections";

        $sections = app('cache')->store('database')->get($key);

        if (!$sections) {
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
        
        $section = Section::create($validated);

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

        if (!$cachedSection) {
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
                'name' => 'unique:sections,name,' . $section->id . ',id,room_id,' . $section->room_id,
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
                $deleted += Section::where('id', $id)->delete();
                app('cache')->store('database')->forget("tenant_{$tenantId}_section_{$id}");
            } catch (\Illuminate\Database\QueryException $e) {
                $skipped[] = ['id' => $id, 'reason' => $e->getMessage()];
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
        $sections = Section::with('room:id,name')->select('id', 'name', 'order_index', 'room_id')->get();

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

        $pdfService = new ExportPDF();
        $pdf = $pdfService->generatePdf($title, $headers, $data);
        return $pdf->download('Sections.pdf');
    }

    public function importFromExcel(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv',
        ]);

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
            }
        );

        Excel::import($import, $request->file('file'));

        app('cache')->store('database')->forget('tenant_' . tenant('id') . '_sections');

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

        if (!$sections) {
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
