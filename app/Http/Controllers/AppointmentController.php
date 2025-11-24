<?php

namespace App\Http\Controllers;

use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Http\Requests\Appointment\StoreAppointmentRequest;
use App\Http\Requests\Appointment\UpdateAppointmentRequest;
use App\Imports\DynamicExcelImport;
use App\Models\Appointment;
use App\Services\SchedulerService;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class AppointmentController extends Controller
{
    protected SchedulerService $schedulerService;

    public function __construct(SchedulerService $schedulerService)
    {
        $this->schedulerService = $schedulerService;
    }

    public function index()
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_appointments";

        $appointments = app('cache')->store('database')->get($key);

        if (! $appointments) {
            $appointments = Appointment::with([
                'asset:id,name,type,status,section_id',
                'asset.section:id,name,room_id',
                'asset.section.room:id,name,location',
                'specialist:id,name',
            ])->orderBy('start_at', 'desc')->get();

            // Recalculate status for appointments that might have changed
            $this->updateAppointmentStatuses($appointments);

            // Cache for 5 minutes (allows status updates to be reflected reasonably quickly)
            app('cache')->store('database')->put($key, $appointments, 300);
        } else {
            // Only recalculate status for cached appointments that might have changed
            // This is much more efficient than checking all appointments every time
            $this->updateAppointmentStatuses($appointments);
        }

        return response()->json([
            'status' => true,
            'message' => 'Appointments fetched successfully.',
            'data' => $appointments,
        ]);
    }

    /**
     * Update appointment statuses based on current time
     * Only checks appointments that might have changed status (performance optimization)
     */
    protected function updateAppointmentStatuses($appointments): void
    {
        $now = now();
        $updated = false;

        // Only check appointments that might need status updates
        // This avoids unnecessary calculations for appointments that can't have changed
        foreach ($appointments as $appointment) {
            // Skip if appointment is far in the future or far in the past (won't change)
            // Only check appointments near current time or that might have transitioned
            $startAt = $appointment->start_at;
            $endAt = $appointment->end_at;

            // Only recalculate if:
            // 1. Status is 'active' and start_at has passed (might be in_progress or completed)
            // 2. Status is 'in_progress' and end_at has passed (might be completed)
            // 3. Status is 'completed' but end_at hasn't passed yet (shouldn't happen, but check anyway)
            $needsCheck = false;

            if ($appointment->status === 'active' && $now->gte($startAt)) {
                $needsCheck = true;
            } elseif ($appointment->status === 'in_progress' && $endAt && $now->gt($endAt)) {
                $needsCheck = true;
            } elseif ($appointment->status === 'completed' && $endAt && $now->lte($endAt)) {
                $needsCheck = true; // Edge case: shouldn't be completed if not past end
            }

            if ($needsCheck) {
                $newStatus = $appointment->calculateStatus();
                if ($appointment->status !== $newStatus) {
                    $appointment->status = $newStatus;
                    $appointment->saveQuietly(); // Save without triggering events to avoid recursion
                    $updated = true;
                }
            }
        }

        // Clear cache if any statuses were updated
        if ($updated) {
            $tenantId = tenant('id');
            app('cache')->store('database')->forget("tenant_{$tenantId}_appointments");
        }
    }

    public function store(StoreAppointmentRequest $request)
    {
        $validated = $request->validated();

        // Validation is handled by AppointmentValidationService in the request
        // Restrictions are checked automatically based on scheduler_mode
        // Status will be auto-calculated by the model's boot method

        $nextId = $this->computeNextAvailableId(Appointment::class, 'id');
        $appointment = new Appointment($validated);
        $appointment->id = $nextId;
        // Status will be auto-calculated in the saving event
        $appointment->save();

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_appointments");

        // Clear scheduler cache for specialist and asset if they exist
        if ($appointment->specialist_id) {
            $this->schedulerService->clearCache('App\Models\Specialist', $appointment->specialist_id);
        }
        if ($appointment->asset_id) {
            $this->schedulerService->clearCache('App\Models\Asset', $appointment->asset_id);
        }

        return response()->json([
            'status' => true,
            'message' => 'Appointment created successfully.',
            'data' => $appointment->load([
                'asset:id,name,type,status,section_id',
                'asset.section:id,name,room_id',
                'asset.section.room:id,name,location',
                'specialist:id,name',
            ]),
        ], 201);
    }

    public function show(Appointment $appointment)
    {
        // Recalculate status before returning (in case time has passed)
        $newStatus = $appointment->calculateStatus();
        if ($appointment->status !== $newStatus) {
            $appointment->status = $newStatus;
            $appointment->saveQuietly(); // Save without triggering events
        }

        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_appointment_{$appointment->id}";

        $cachedAppointment = app('cache')->store('database')->get($key);

        if (! $cachedAppointment || $appointment->status !== $newStatus) {
            $cachedAppointment = $appointment->load([
                'asset:id,name,type,status,section_id',
                'asset.section:id,name,room_id',
                'asset.section.room:id,name,location',
                'specialist:id,name',
            ]);

            app('cache')->store('database')->forever($key, $cachedAppointment);
        }

        return response()->json([
            'status' => true,
            'message' => 'Appointment details fetched successfully.',
            'data' => $cachedAppointment,
        ]);
    }

    public function update(UpdateAppointmentRequest $request, Appointment $appointment)
    {
        $validated = $request->validated();

        // Validation and restrictions are handled by AppointmentValidationService in the request
        // Status will be auto-calculated by the model's boot method if time fields change

        $oldSpecialistId = $appointment->specialist_id;
        $oldAssetId = $appointment->asset_id;

        // Remove status from validated data if present (shouldn't be, but just in case)
        unset($validated['status']);

        $appointment->update($validated);

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_appointments");
        app('cache')->store('database')->forget("tenant_{$tenantId}_appointment_{$appointment->id}");

        // Clear scheduler cache for old and new specialist/asset
        if ($oldSpecialistId) {
            $this->schedulerService->clearCache('App\Models\Specialist', $oldSpecialistId);
        }
        if ($appointment->specialist_id && $appointment->specialist_id !== $oldSpecialistId) {
            $this->schedulerService->clearCache('App\Models\Specialist', $appointment->specialist_id);
        }
        if ($oldAssetId) {
            $this->schedulerService->clearCache('App\Models\Asset', $oldAssetId);
        }
        if ($appointment->asset_id && $appointment->asset_id !== $oldAssetId) {
            $this->schedulerService->clearCache('App\Models\Asset', $appointment->asset_id);
        }

        return response()->json([
            'status' => true,
            'message' => 'Appointment updated successfully.',
            'data' => $appointment->load([
                'asset:id,name,type,status,section_id',
                'asset.section:id,name,room_id',
                'asset.section.room:id,name,location',
                'specialist:id,name',
            ]),
        ]);
    }

    public function destroy(Appointment $appointment)
    {
        $specialistId = $appointment->specialist_id;
        $assetId = $appointment->asset_id;

        $appointment->delete();

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_appointments");
        app('cache')->store('database')->forget("tenant_{$tenantId}_appointment_{$appointment->id}");

        // Clear scheduler cache for specialist and asset if they exist
        if ($specialistId) {
            $this->schedulerService->clearCache('App\Models\Specialist', $specialistId);
        }
        if ($assetId) {
            $this->schedulerService->clearCache('App\Models\Asset', $assetId);
        }

        return response()->json([
            'status' => true,
            'message' => 'Appointment deleted successfully.',
        ]);
    }

    public function bulkDelete(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:appointments,id',
        ]);

        $tenantId = tenant('id');
        $skipped = [];
        $deleted = 0;

        foreach ($request->ids as $id) {
            try {
                $deleted += Appointment::where('id', $id)->delete();
                app('cache')->store('database')->forget("tenant_{$tenantId}_appointment_{$id}");
            } catch (\Illuminate\Database\QueryException $e) {
                $skipped[] = ['id' => $id, 'reason' => $e->getMessage()];
            }
        }

        app('cache')->store('database')->forget("tenant_{$tenantId}_appointments");

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
        $appointments = Appointment::with([
            'asset:id,name,type,status,section_id',
            'asset.section:id,name,room_id',
            'asset.section.room:id,name,location',
            'specialist:id,name',
        ])->orderBy('start_at', 'desc');
        $collection = $appointments->get();

        if ($collection->isEmpty()) {
            return response()->json(['message' => 'No appointments found.'], 404);
        }

        $columns = ['id', 'asset_id', 'specialist_id', 'start_at', 'end_at', 'status', 'notes', 'created_at', 'updated_at'];
        $headings = ['ID', 'Asset ID', 'Specialist ID', 'Start At', 'End At', 'Status', 'Notes', 'Created At', 'Updated At'];

        return Excel::download(new Export($appointments, $columns, $headings), 'appointments.xlsx');
    }

    public function exportPdf()
    {
        $appointments = Appointment::with([
            'asset:id,name,type,status,section_id',
            'asset.section:id,name,room_id',
            'asset.section.room:id,name,location',
            'specialist:id,name',
        ])->select('id', 'asset_id', 'specialist_id', 'start_at', 'end_at', 'status', 'notes')->get();

        if ($appointments->isEmpty()) {
            return response()->json(['message' => 'No appointments found.'], 404);
        }

        $title = 'Appointment Report';
        $headers = [
            'id' => 'Appointment ID',
            'asset_id' => 'Asset ID',
            'specialist_id' => 'Specialist ID',
            'start_at' => 'Start At',
            'end_at' => 'End At',
            'status' => 'Status',
            'notes' => 'Notes',
        ];
        $data = $appointments->toArray();

        $pdfService = new ExportPDF;
        $pdf = $pdfService->generatePdf($title, $headers, $data);

        return $pdf->download('Appointments.pdf');
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
            Appointment::truncate();
        }

        // If type is 'mapping', use provided mapping, else use default
        $mapping = $request->input('mapping');
        $fields = $mapping ? array_values($mapping) : ['asset_id', 'specialist_id', 'start_at', 'end_at', 'status', 'notes'];

        $import = new DynamicExcelImport(
            Appointment::class,
            $fields,
            function ($row) use ($mapping) {
                $errors = [];
                $assetIdKey = $mapping ? array_search('asset_id', $mapping) : 'asset_id';
                $specialistIdKey = $mapping ? array_search('specialist_id', $mapping) : 'specialist_id';

                if (empty($row[$assetIdKey])) {
                    $errors[] = 'Missing asset_id';
                }
                if (empty($row[$specialistIdKey])) {
                    $errors[] = 'Missing specialist_id';
                }
                $startAtKey = $mapping ? array_search('start_at', $mapping) : 'start_at';
                if (empty($row[$startAtKey])) {
                    $errors[] = 'Missing start_at';
                }

                return $errors;
            },
            function ($row) use ($mapping) {
                $assetIdKey = $mapping ? array_search('asset_id', $mapping) : 'asset_id';
                $specialistIdKey = $mapping ? array_search('specialist_id', $mapping) : 'specialist_id';
                $startAtKey = $mapping ? array_search('start_at', $mapping) : 'start_at';
                $endAtKey = $mapping ? array_search('end_at', $mapping) : 'end_at';
                $statusKey = $mapping ? array_search('status', $mapping) : 'status';
                $notesKey = $mapping ? array_search('notes', $mapping) : 'notes';

                return [
                    'asset_id' => $row[$assetIdKey] ?? null,
                    'specialist_id' => $row[$specialistIdKey] ?? null,
                    'start_at' => $row[$startAtKey] ?? null,
                    'end_at' => $row[$endAtKey] ?? null,
                    'status' => $row[$statusKey] ?? 'active',
                    'notes' => $row[$notesKey] ?? null,
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

        app('cache')->store('database')->forget('tenant_'.tenant('id').'_appointments');

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

    public function byAsset($assetId)
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_asset_{$assetId}_appointments";

        $appointments = app('cache')->store('database')->get($key);

        if (! $appointments) {
            $appointments = Appointment::where('asset_id', $assetId)
                ->with([
                    'asset:id,name,type,status,section_id',
                    'asset.section:id,name,room_id',
                    'asset.section.room:id,name,location',
                    'specialist:id,name',
                ])
                ->orderBy('start_at', 'desc')
                ->get();

            app('cache')->store('database')->forever($key, $appointments);
        }

        return response()->json([
            'status' => true,
            'message' => 'Appointments for asset fetched successfully.',
            'data' => $appointments,
        ]);
    }

    public function bySpecialist($specialistId)
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_specialist_{$specialistId}_appointments";

        $appointments = app('cache')->store('database')->get($key);

        if (! $appointments) {
            $appointments = Appointment::bySpecialist($specialistId)
                ->with([
                    'asset:id,name,type,status,section_id',
                    'asset.section:id,name,room_id',
                    'asset.section.room:id,name,location',
                    'specialist:id,name',
                ])
                ->orderBy('start_at', 'desc')
                ->get();

            app('cache')->store('database')->forever($key, $appointments);
        }

        return response()->json([
            'status' => true,
            'message' => 'Appointments for specialist fetched successfully.',
            'data' => $appointments,
        ]);
    }

    public function active()
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_active_appointments";

        $appointments = app('cache')->store('database')->get($key);

        if (! $appointments) {
            $appointments = Appointment::active()
                ->with([
                    'asset:id,name,type,status,section_id',
                    'asset.section:id,name,room_id',
                    'asset.section.room:id,name,location',
                    'specialist:id,name',
                ])
                ->orderBy('start_at', 'desc')
                ->get();

            app('cache')->store('database')->forever($key, $appointments);
        }

        return response()->json([
            'status' => true,
            'message' => 'Active appointments fetched successfully.',
            'data' => $appointments,
        ]);
    }
}
