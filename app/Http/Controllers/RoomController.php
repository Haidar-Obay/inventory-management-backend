<?php

namespace App\Http\Controllers;

use App\Models\Room;
use App\Http\Requests\Room\StoreRoomRequest;
use App\Http\Requests\Room\UpdateRoomRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

use Maatwebsite\Excel\Facades\Excel;
use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Imports\DynamicExcelImport;

class RoomController extends Controller
{
    public function index()
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_rooms";

        $rooms = app('cache')->store('database')->get($key);

        if (!$rooms) {
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
        
        $room = Room::create($validated);

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

        if (!$cachedRoom) {
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
                'name' => 'unique:rooms,name,' . $room->id,
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
                $deleted += Room::where('id', $id)->delete();
                app('cache')->store('database')->forget("tenant_{$tenantId}_room_{$id}");
            } catch (\Illuminate\Database\QueryException $e) {
                $skipped[] = ['id' => $id, 'reason' => $e->getMessage()];
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

        $pdfService = new ExportPDF();
        $pdf = $pdfService->generatePdf($title, $headers, $data);
        return $pdf->download('Rooms.pdf');
    }

    public function importFromExcel(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv',
        ]);

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
            }
        );

        Excel::import($import, $request->file('file'));

        app('cache')->store('database')->forget('tenant_' . tenant('id') . '_rooms');

        return response()->json([
            'success' => true,
            'rows_imported' => $import->getImportedCount(),
            'rows_skipped_count' => $import->getSkippedCount(),
            'skipped_rows' => $import->getSkippedRows(),
        ]);
    }
}
