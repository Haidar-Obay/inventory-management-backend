<?php

namespace App\Http\Controllers;

use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Http\Requests\Appointment\StoreAppointmentRequest;
use App\Http\Requests\Appointment\UpdateAppointmentRequest;
use App\Imports\DynamicExcelImport;
use App\Models\Appointment;
use App\Models\Specialist;
use App\Models\Asset;
use App\Services\SchedulerService;
use App\Services\AppointmentAvailabilityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class AppointmentController extends Controller
{
    protected SchedulerService $schedulerService;
    protected AppointmentAvailabilityService $availabilityService;

    public function __construct(SchedulerService $schedulerService, AppointmentAvailabilityService $availabilityService)
    {
        $this->schedulerService = $schedulerService;
        $this->availabilityService = $availabilityService;
    }

    /**
     * Helper method to load specialists and assets for services based on pivot data
     */
    protected function loadServiceRelations(Appointment $appointment): void
    {
        // Load services with pivot data if not already loaded
        if (!$appointment->relationLoaded('services')) {
            $appointment->load('services:id,name');
        }

        // Get unique specialist and asset IDs from pivot
        $specialistIds = $appointment->services->pluck('pivot.specialist_id')->filter()->unique()->toArray();
        $assetIds = $appointment->services->pluck('pivot.asset_id')->filter()->unique()->toArray();

        // Load specialists and assets
        $specialists = $specialistIds ? Specialist::whereIn('id', $specialistIds)->get()->keyBy('id') : collect();
        $assets = $assetIds ? Asset::with(['section:id,name,room_id', 'section.room:id,name,location'])
            ->whereIn('id', $assetIds)
            ->get()
            ->keyBy('id') : collect();

        // Attach specialists and assets to services
        $appointment->services->each(function ($service) use ($specialists, $assets) {
            $specialistId = $service->pivot->specialist_id ?? null;
            $assetId = $service->pivot->asset_id ?? null;
            
            if ($specialistId && $specialists->has($specialistId)) {
                $service->setRelation('specialist', $specialists->get($specialistId));
            }
            
            if ($assetId && $assets->has($assetId)) {
                $service->setRelation('asset', $assets->get($assetId));
            }
        });
    }

    public function index(Request $request)
    {
        $tenantId = tenant('id');

        // Get date range parameters
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');

        // Build cache key with date range if provided
        $key = "tenant_{$tenantId}_appointments";
        if ($startDate) {
            $key .= "_from_{$startDate}";
        }
        if ($endDate) {
            $key .= "_to_{$endDate}";
        }

        $appointments = app('cache')->store('database')->get($key);

        if (! $appointments) {
            $query = Appointment::with([
                'services:id,name',
                'customers:id,first_name,middle_name,last_name',
            ]);

            // Apply date range filtering if provided
            if ($startDate) {
                $query->whereDate('start_at', '>=', $startDate);
            }
            if ($endDate) {
                $query->whereDate('start_at', '<=', $endDate);
            }

            $appointments = $query->orderBy('start_at', 'desc')->get();

            // Load specialists and assets for services in each appointment
            $appointments->each(function ($appointment) {
                $this->loadServiceRelations($appointment);
            });

            // Recalculate status for appointments that might have changed
            $this->updateAppointmentStatuses($appointments);

            // Cache for 5 minutes (allows status updates to be reflected reasonably quickly)
            // Use shorter cache time for date-filtered queries
            $cacheTime = ($startDate || $endDate) ? 60 : 300;
            app('cache')->store('database')->put($key, $appointments, $cacheTime);
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

        // Extract customer IDs if provided (optional)
        $customerIds = $validated['customer_ids'] ?? null;
        unset($validated['customer_ids']);

        // Extract services data - supports both formats:
        // 1. service_ids array (simple array of IDs)
        // 2. services array (array of objects with service_id and specialist_id)
        $services = $validated['services'] ?? null;
        $serviceIds = $validated['service_ids'] ?? null;
        unset($validated['services'], $validated['service_ids']);

        // Handle legacy service_id for backward compatibility
        // If service_id is provided but services/service_ids is not, convert service_id to services array
        if (isset($validated['service_id']) && $validated['service_id'] && ! $services && ! $serviceIds) {
            $services = [
                [
                    'service_id' => $validated['service_id'],
                    'specialist_id' => null, // No appointment-level specialist_id anymore
                ],
            ];
        }
        unset($validated['service_id']);

        // Validation is handled by AppointmentValidationService in the request
        // Restrictions are checked automatically based on scheduler_mode
        // Status will be auto-calculated by the model's boot method

        $nextId = $this->computeNextAvailableId(Appointment::class, 'id');
        $appointment = new Appointment($validated);
        $appointment->id = $nextId;
        // Status will be auto-calculated in the saving event
        $appointment->save();

        // Attach customers if provided
        if (! empty($customerIds) && is_array($customerIds)) {
            $appointment->customers()->sync($customerIds);
        }

        // Attach services with their specialists and assets if provided
        if (! empty($services) && is_array($services)) {
            // Format: [['service_id' => 1, 'specialist_id' => 5, 'asset_id' => 3], ...]
            $syncData = [];
            foreach ($services as $serviceData) {
                $serviceId = is_array($serviceData) ? ($serviceData['service_id'] ?? $serviceData) : $serviceData;
                $specialistId = is_array($serviceData) ? ($serviceData['specialist_id'] ?? null) : null;
                $assetId = is_array($serviceData) ? ($serviceData['asset_id'] ?? null) : null;
                $syncData[$serviceId] = [
                    'specialist_id' => $specialistId,
                    'asset_id' => $assetId,
                ];
            }
            $appointment->services()->sync($syncData);
        } elseif (! empty($serviceIds) && is_array($serviceIds)) {
            // Simple array format - no specialists or assets specified
            $appointment->services()->sync($serviceIds);
        }

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_appointments");

        // Clear scheduler cache for specialists and assets if they exist
        // Clear cache for all service-level specialists and assets
        if (! empty($services) && is_array($services)) {
            foreach ($services as $serviceData) {
                $specialistId = is_array($serviceData) ? ($serviceData['specialist_id'] ?? null) : null;
                $assetId = is_array($serviceData) ? ($serviceData['asset_id'] ?? null) : null;
                if ($specialistId) {
                    $this->schedulerService->clearCache('App\Models\Specialist', $specialistId);
        }
                if ($assetId) {
                    $this->schedulerService->clearCache('App\Models\Asset', $assetId);
                }
            }
        }

        // Load services and customers
        $appointment->load([
            'services:id,name',
            'customers:id,first_name,middle_name,last_name',
        ]);

        // Load specialists and assets for services
        $this->loadServiceRelations($appointment);

        return response()->json([
            'status' => true,
            'message' => 'Appointment created successfully.',
            'data' => $appointment,
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
                'service:id,name',
                'services:id,name',
                'customers:id,first_name,middle_name,last_name',
            ]);

            // Load specialists and assets for services
            $this->loadServiceRelations($cachedAppointment);

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

        // Extract customer IDs if provided (optional on update)
        $customerIds = $validated['customer_ids'] ?? null;
        unset($validated['customer_ids']);

        // Extract services data - supports both formats:
        // 1. service_ids array (simple array of IDs)
        // 2. services array (array of objects with service_id and specialist_id)
        $services = $validated['services'] ?? null;
        $serviceIds = $validated['service_ids'] ?? null;
        unset($validated['services'], $validated['service_ids']);

        // Handle legacy service_id for backward compatibility
        if (isset($validated['service_id']) && $validated['service_id'] && ! $services && ! $serviceIds) {
            $services = [
                [
                    'service_id' => $validated['service_id'],
                    'specialist_id' => null, // No appointment-level specialist_id anymore
                    'asset_id' => null, // No appointment-level asset_id anymore
                ],
            ];
        }
        unset($validated['service_id']);

        // Validation and restrictions are handled by AppointmentValidationService in the request
        // Status will be auto-calculated by the model's boot method if time fields change

        // Remove status from validated data if present (shouldn't be, but just in case)
        unset($validated['status']);

        $appointment->update($validated);

        // Sync customers if provided; allow clearing by sending empty array
        if ($request->has('customer_ids')) {
            $appointment->customers()->sync($customerIds ?? []);
        }

        // Sync services with their specialists and assets if provided
        if ($request->has('services') || $request->has('service_ids') || $request->has('service_id')) {
            if (is_array($services)) {
                // Format: [['service_id' => 1, 'specialist_id' => 5, 'asset_id' => 3], ...]
                // Handle empty array to clear all services
                if (empty($services)) {
                    $appointment->services()->sync([]);
                } else {
                    $syncData = [];
                    foreach ($services as $serviceData) {
                        $serviceId = is_array($serviceData) ? ($serviceData['service_id'] ?? $serviceData) : $serviceData;
                        if ($serviceId) {
                            $specialistId = is_array($serviceData) ? ($serviceData['specialist_id'] ?? null) : null;
                            $assetId = is_array($serviceData) ? ($serviceData['asset_id'] ?? null) : null;
                            $syncData[$serviceId] = [
                                'specialist_id' => $specialistId,
                                'asset_id' => $assetId,
                            ];
                        }
                    }
                    $appointment->services()->sync($syncData);
                }
            } elseif (! empty($serviceIds) && is_array($serviceIds)) {
                // Simple array format - preserve existing specialists and assets if any
                $existingServices = $appointment->services()->get();
                $syncData = [];
                foreach ($serviceIds as $serviceId) {
                    $existing = $existingServices->firstWhere('id', $serviceId);
                    $syncData[$serviceId] = [
                        'specialist_id' => $existing->pivot->specialist_id ?? null,
                        'asset_id' => $existing->pivot->asset_id ?? null,
                    ];
                }
                $appointment->services()->sync($syncData);
            } else {
                // Clear all services
                $appointment->services()->sync([]);
            }
        }

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_appointments");
        app('cache')->store('database')->forget("tenant_{$tenantId}_appointment_{$appointment->id}");

        // Clear cache for all service-level specialists and assets (old and new)
        $oldServiceData = DB::table('appointment_service')
            ->where('appointment_id', $appointment->id)
            ->get();
        
        $oldServiceSpecialists = $oldServiceData->pluck('specialist_id')->filter()->unique()->toArray();
        $oldServiceAssets = $oldServiceData->pluck('asset_id')->filter()->unique()->toArray();
        
        foreach ($oldServiceSpecialists as $specialistId) {
            $this->schedulerService->clearCache('App\Models\Specialist', $specialistId);
        }
        foreach ($oldServiceAssets as $assetId) {
            $this->schedulerService->clearCache('App\Models\Asset', $assetId);
        }
        
        if (! empty($services) && is_array($services)) {
            foreach ($services as $serviceData) {
                $specialistId = is_array($serviceData) ? ($serviceData['specialist_id'] ?? null) : null;
                $assetId = is_array($serviceData) ? ($serviceData['asset_id'] ?? null) : null;
                if ($specialistId && ! in_array($specialistId, $oldServiceSpecialists)) {
                    $this->schedulerService->clearCache('App\Models\Specialist', $specialistId);
        }
                if ($assetId && ! in_array($assetId, $oldServiceAssets)) {
                    $this->schedulerService->clearCache('App\Models\Asset', $assetId);
        }
            }
        }

        // Load services and customers
        $appointment->load([
            'services:id,name',
            'customers:id,first_name,middle_name,last_name',
        ]);

        // Load specialists and assets for services
        $this->loadServiceRelations($appointment);

        return response()->json([
            'status' => true,
            'message' => 'Appointment updated successfully.',
            'data' => $appointment,
        ]);
    }

    public function destroy(Appointment $appointment)
    {
        // Get all specialists from pivot table before deletion
        $specialistIds = DB::table('appointment_service')
            ->where('appointment_id', $appointment->id)
            ->whereNotNull('specialist_id')
            ->pluck('specialist_id')
            ->unique()
            ->toArray();
        
        $assetId = $appointment->asset_id;

        $appointment->delete();

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_appointments");
        app('cache')->store('database')->forget("tenant_{$tenantId}_appointment_{$appointment->id}");

        // Clear scheduler cache for all specialists and asset if they exist
        foreach ($specialistIds as $specialistId) {
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
            'customers:id,first_name,middle_name,last_name',
        ])->orderBy('start_at', 'desc');
        $collection = $appointments->get();

        if ($collection->isEmpty()) {
            return response()->json(['message' => 'No appointments found.'], 404);
        }

        $columns = ['id', 'asset_id', 'start_at', 'end_at', 'status', 'notes', 'created_at', 'updated_at'];
        $headings = ['ID', 'Asset ID', 'Start At', 'End At', 'Status', 'Notes', 'Created At', 'Updated At'];

        return Excel::download(new Export($appointments, $columns, $headings), 'appointments.xlsx');
    }

    public function exportPdf()
    {
        $appointments = Appointment::with([
            'asset:id,name,type,status,section_id',
            'asset.section:id,name,room_id',
            'asset.section.room:id,name,location',
            'specialist:id,name',
            'services:id,name',
        ])->select('id', 'asset_id', 'start_at', 'end_at', 'status', 'notes')->get();

        if ($appointments->isEmpty()) {
            return response()->json(['message' => 'No appointments found.'], 404);
        }

        $title = 'Appointment Report';
        $headers = [
            'id' => 'Appointment ID',
            'asset_id' => 'Asset ID',
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
        $fields = $mapping ? array_values($mapping) : ['asset_id', 'start_at', 'end_at', 'status', 'notes'];

        $import = new DynamicExcelImport(
            Appointment::class,
            $fields,
            function ($row) use ($mapping) {
                $errors = [];
                $assetIdKey = $mapping ? array_search('asset_id', $mapping) : 'asset_id';

                if (empty($row[$assetIdKey])) {
                    $errors[] = 'Missing asset_id';
                }
                $startAtKey = $mapping ? array_search('start_at', $mapping) : 'start_at';
                if (empty($row[$startAtKey])) {
                    $errors[] = 'Missing start_at';
                }

                return $errors;
            },
            function ($row) use ($mapping) {
                $assetIdKey = $mapping ? array_search('asset_id', $mapping) : 'asset_id';
                $startAtKey = $mapping ? array_search('start_at', $mapping) : 'start_at';
                $endAtKey = $mapping ? array_search('end_at', $mapping) : 'end_at';
                $statusKey = $mapping ? array_search('status', $mapping) : 'status';
                $notesKey = $mapping ? array_search('notes', $mapping) : 'notes';

                return [
                    'asset_id' => $row[$assetIdKey] ?? null,
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
            $appointments = Appointment::whereHas('services', function ($q) use ($assetId) {
                    $q->where('appointment_service.asset_id', $assetId);
                })
                ->with([
                    'services:id,name',
                    'customers:id,first_name,middle_name,last_name',
                ])
                ->orderBy('start_at', 'desc')
                ->get();

            // Load specialists and assets for services in each appointment
            $appointments->each(function ($appointment) {
                $this->loadServiceRelations($appointment);
            });

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
                    'services:id,name',
                    'customers:id,first_name,middle_name,last_name',
                ])
                ->orderBy('start_at', 'desc')
                ->get();

            // Load specialists and assets for services in each appointment
            $appointments->each(function ($appointment) {
                $this->loadServiceRelations($appointment);
            });

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
                    'services:id,name',
                    'customers:id,first_name,middle_name,last_name',
                ])
                ->orderBy('start_at', 'desc')
                ->get();

            // Load specialists and assets for services in each appointment
            $appointments->each(function ($appointment) {
                $this->loadServiceRelations($appointment);
            });

            app('cache')->store('database')->forever($key, $appointments);
        }

        return response()->json([
            'status' => true,
            'message' => 'Active appointments fetched successfully.',
            'data' => $appointments,
        ]);
    }

    /**
     * Find available appointment slots for given services
     */
    public function findAvailableSlots(Request $request)
    {
        $request->validate([
            'services' => 'required|array|min:1',
            'services.*.service_id' => 'required|exists:services,id',
            'services.*.specialist_id' => 'nullable|exists:specialists,id',
            'services.*.asset_id' => 'nullable|exists:assets,id',
            'days_ahead' => 'nullable|integer|min:1|max:90',
            'start_date' => 'nullable|date',
        ]);

        $services = $request->input('services');
        $daysAhead = $request->input('days_ahead', 30);
        $startDate = $request->input('start_date');

        try {
            $result = $this->availabilityService->findAvailableSlots($services, $daysAhead, $startDate);

            return response()->json([
                'status' => true,
                'message' => 'Available slots fetched successfully.',
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to find available slots: ' . $e->getMessage(),
            ], 500);
        }
    }
}
