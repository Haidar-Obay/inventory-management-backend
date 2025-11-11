<?php

namespace App\Http\Controllers;

use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Http\Requests\Room\StoreRoomRequest;
use App\Http\Requests\Room\UpdateRoomRequest;
use App\Imports\DynamicExcelImport;
use App\Models\Room;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;

class RoomController extends Controller
{
    public function index()
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_rooms";

        $rooms = app('cache')->store('database')->get($key);

        if (! $rooms) {
            $rooms = Room::orderBy('name')->get();

            app('cache')->store('database')->forever($key, $rooms);
        }

        return response()->json([
            'status' => true,
            'message' => 'Rooms fetched successfully.',
            'data' => $rooms,
        ]);
    }

    public function store(StoreRoomRequest $request)
    {
        $validated = $request->validated();

        $nextId = $this->computeNextAvailableId(Room::class, 'id');
        $room = new Room($validated);
        $room->id = $nextId;
        $room->save();

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_rooms");

        return response()->json([
            'status' => true,
            'message' => 'Room created successfully.',
            'data' => $room,
        ], 201);
    }

    public function show(Room $room)
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_room_{$room->id}";

        $cachedRoom = app('cache')->store('database')->get($key);

        if (! $cachedRoom) {
            $cachedRoom = $room;

            app('cache')->store('database')->forever($key, $cachedRoom);
        }

        return response()->json([
            'status' => true,
            'message' => 'Room details fetched successfully.',
            'data' => $cachedRoom,
        ]);
    }

    public function update(UpdateRoomRequest $request, Room $room)
    {
        $validated = $request->validated();

        // Handle unique validation for name field
        if (isset($validated['name'])) {
            $validator = Validator::make(['name' => $validated['name']], [
                'name' => 'unique:rooms,name,'.$room->id,
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }
        }

        $room->update($validated);

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_rooms");
        app('cache')->store('database')->forget("tenant_{$tenantId}_room_{$room->id}");

        return response()->json([
            'status' => true,
            'message' => 'Room updated successfully.',
            'data' => $room,
        ]);
    }

    public function destroy(Room $room)
    {
        $identifier = $room->name ?? "ID: {$room->id}";
        $details = [];

        // Check if room has sections
        if ($room->sections()->exists()) {
            $sectionsCount = $room->sections()->count();
            $details['sections'] = [
                'count' => $sectionsCount,
                'sample_ids' => $room->sections()->select('sections.id')->limit(1)->pluck('id'),
            ];
        }

        // Check if room has assets (through sections)
        if ($room->assets()->exists()) {
            $assetsCount = $room->assets()->count();
            $details['assets'] = [
                'count' => $assetsCount,
                'sample_ids' => $room->assets()->select('assets.id')->limit(1)->pluck('id'),
            ];
        }

        if (! empty($details)) {
            return response()->json([
                'status' => false,
                'message' => "Cannot delete room \"{$identifier}\" (ID: {$room->id}). It is referenced by existing records.",
                'details' => $details,
            ], 409);
        }

        $room->delete();

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_rooms");
        app('cache')->store('database')->forget("tenant_{$tenantId}_room_{$room->id}");

        return response()->json([
            'status' => true,
            'message' => 'Room deleted successfully.',
        ]);
    }

    public function bulkDelete(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:rooms,id',
        ]);

        $tenantId = tenant('id');
        $skipped = [];
        $deleted = 0;

        foreach ($request->ids as $id) {
            try {
                $room = Room::find($id);

                if (! $room) {
                    $skipped[] = [
                        'id' => $id,
                        'name' => "ID: {$id}",
                        'reason' => 'Room not found.',
                    ];

                    continue;
                }

                $identifier = $room->name ?? "ID: {$id}";
                $details = [];

                // Check if room has sections
                if ($room->sections()->exists()) {
                    $sectionsCount = $room->sections()->count();
                    $details['sections'] = [
                        'count' => $sectionsCount,
                        'sample_ids' => $room->sections()->select('sections.id')->limit(1)->pluck('id'),
                    ];
                }

                // Check if room has assets (through sections)
                if ($room->assets()->exists()) {
                    $assetsCount = $room->assets()->count();
                    $details['assets'] = [
                        'count' => $assetsCount,
                        'sample_ids' => $room->assets()->select('assets.id')->limit(1)->pluck('id'),
                    ];
                }

                if (! empty($details)) {
                    $skipped[] = [
                        'id' => $id,
                        'name' => $identifier,
                        'reason' => 'Cannot delete room. It is referenced by existing records.',
                        'details' => $details,
                    ];

                    continue;
                }

                $deleted += $room->delete();
                app('cache')->store('database')->forget("tenant_{$tenantId}_room_{$id}");
            } catch (\Illuminate\Database\QueryException $e) {
                $room = Room::find($id);
                $identifier = $room?->name ?? "ID: {$id}";
                $skipped[] = [
                    'id' => $id,
                    'name' => $identifier,
                    'reason' => $e->getMessage(),
                ];
            }
        }

        app('cache')->store('database')->forget("tenant_{$tenantId}_rooms");

        return response()->json([
            'message' => 'Bulk delete completed.',
            'deleted_count' => $deleted,
            'skipped' => $skipped,
        ]);
    }

    public function exportExcell()
    {
        $rooms = Room::orderBy('name');
        $collection = $rooms->get();

        if ($collection->isEmpty()) {
            return response()->json(['message' => 'No rooms found.'], 404);
        }

        $columns = ['id', 'name', 'location', 'created_at', 'updated_at'];
        $headings = ['ID', 'Name', 'Location', 'Created At', 'Updated At'];

        return Excel::download(new Export($rooms, $columns, $headings), 'rooms.xlsx');
    }

    public function exportPdf()
    {
        $rooms = Room::select('id', 'name', 'location')->get();

        if ($rooms->isEmpty()) {
            return response()->json(['message' => 'No rooms found.'], 404);
        }

        $title = 'Room Report';
        $headers = [
            'id' => 'Room ID',
            'name' => 'Room Name',
            'location' => 'Location',
        ];
        $data = $rooms->toArray();

        $pdfService = new ExportPDF;
        $pdf = $pdfService->generatePdf($title, $headers, $data);

        return $pdf->download('Rooms.pdf');
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
            Room::truncate();
        }

        // If type is 'mapping', use provided mapping, else use default
        $mapping = $request->input('mapping');

        $import = new DynamicExcelImport(
            Room::class,
            ['name', 'location'],
            function ($row) {
                $errors = [];

                if (empty($row['name'])) {
                    $errors[] = 'Missing name';
                }
                if (empty($row['location'])) {
                    $errors[] = 'Missing location';
                }

                return $errors;
            },
            function ($row) {
                return [
                    'name' => $row['name'],
                    'location' => $row['location'],
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

        app('cache')->store('database')->forget('tenant_'.tenant('id').'_rooms');

        return response()->json([
            'success' => true,
            'rows_imported' => $import->getImportedCount(),
            'rows_skipped_count' => $import->getSkippedCount(),
            'skipped_rows' => $import->getSkippedRows(),
        ]);
    }
}
