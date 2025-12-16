<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Specialist;
use App\Models\Visit;
use Illuminate\Http\Request;

class VisitController extends Controller
{
    /**
     * Load specialists and assets for appointment services (similar to AppointmentController)
     */
    protected function loadServiceRelations($appointment)
    {
        if (! $appointment) {
            return;
        }

        // Load services with pivot data if not already loaded
        if (! $appointment->relationLoaded('services')) {
            $appointment->load('services:id,name');
        }

        // Get unique specialist and asset IDs from pivot
        $specialistIds = $appointment->services->pluck('pivot.specialist_id')->filter()->unique()->toArray();
        $assetIds = $appointment->services->pluck('pivot.asset_id')->filter()->unique()->toArray();

        // Load specialists and assets
        $specialists = $specialistIds ? Specialist::whereIn('id', $specialistIds)->get()->keyBy('id') : collect();
        $assets = $assetIds ? Asset::whereIn('id', $assetIds)->get()->keyBy('id') : collect();

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

    /**
     * List visits (typically today's visitors), with optional status/date filters.
     */
    public function index(Request $request)
    {
        $date = $request->query('date'); // e.g. 2025-12-15
        $status = $request->query('status'); // arrived, in_progress, completed, cancelled

        $query = Visit::with([
            'customer',
            'appointment.customers',
            'appointment.services',
        ])->orderByDesc('arrived_at')->orderByDesc('id');

        if ($date) {
            $query->whereDate('arrived_at', $date);
        }

        if ($status) {
            $query->where('status', $status);
        }

        // Default to "today" if no date filter provided
        if (! $date) {
            $query->whereDate('arrived_at', now()->toDateString());
        }

        $visits = $query->get();

        // Load specialists and assets for each visit's appointment
        foreach ($visits as $visit) {
            if ($visit->appointment) {
                $this->loadServiceRelations($visit->appointment);
            }
        }

        return response()->json([
            'status' => true,
            'message' => 'Visits fetched successfully.',
            'data' => $visits,
        ]);
    }

    /**
     * Create a new visit (arrival).
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'appointment_id' => 'nullable|exists:appointments,id',
            'notes' => 'nullable|string|max:1000',
        ]);

        // Clear cache BEFORE creating
        $this->clearAppointmentCaches($data['appointment_id'] ?? null);
        // Note: We don't clear scheduler cache when creating a visit because:
        // - The appointment already exists in the scheduler
        // - We're only linking a visit to it, not changing the appointment itself
        // - Status changes don't require scheduler cache clearing

        $visit = new Visit;
        $visit->customer_id = $data['customer_id'];
        $visit->appointment_id = $data['appointment_id'] ?? null;
        $visit->status = 'arrived';
        $visit->arrived_at = now();
        $visit->notes = $data['notes'] ?? null;
        $visit->save();

        // Optionally, we could sync appointment status here (usually remains "active" on arrival)
        // $visit->applyStatusToAppointment();

        $visit->load([
            'customer',
            'appointment.customers',
            'appointment.services',
        ]);

        // Load specialists and assets for appointment services
        if ($visit->appointment) {
            $this->loadServiceRelations($visit->appointment);
        }

        return response()->json([
            'status' => true,
            'message' => 'Visit created successfully.',
            'data' => $visit,
        ], 201);
    }

    /**
     * Show a single visit with relations.
     */
    public function show(Visit $visit)
    {
        $visit->load([
            'customer',
            'appointment.customers',
            'appointment.services',
        ]);

        // Load specialists and assets for appointment services
        if ($visit->appointment) {
            $this->loadServiceRelations($visit->appointment);
        }

        return response()->json([
            'status' => true,
            'message' => 'Visit details fetched successfully.',
            'data' => $visit,
        ]);
    }

    /**
     * Update a visit (status change, notes, etc.).
     *
     * Frontend will mainly use this to move visitors:
     *   arrived -> in_progress -> completed -> cancelled
     */
    public function update(Request $request, Visit $visit)
    {
        $data = $request->validate([
            'status' => 'nullable|in:arrived,in_progress,completed,cancelled',
            'notes' => 'nullable|string|max:1000',
            'cancellation_reason' => 'nullable|string|max:1000',
        ]);

        // Clear cache BEFORE updating (especially if status changes affect appointment)
        $this->clearAppointmentCaches($visit->appointment_id);

        $originalStatus = $visit->status;

        if (array_key_exists('notes', $data)) {
            $visit->notes = $data['notes'];
        }

        if (array_key_exists('cancellation_reason', $data)) {
            $visit->cancellation_reason = $data['cancellation_reason'];
        }

        if (! empty($data['status'])) {
            $visit->status = $data['status'];
        }

        $visit->save();

        // If status changed, propagate to appointment
        if ($originalStatus !== $visit->status) {
            $visit->load('appointment'); // ensure relation is loaded
            $visit->applyStatusToAppointment();
            // Note: We don't clear scheduler cache here because we're only updating status
            // Scheduler cache is only cleared when appointments/services/specialists/assets change
        }

        $visit->load([
            'customer',
            'appointment.customers',
            'appointment.services',
        ]);

        // Load specialists and assets for appointment services
        if ($visit->appointment) {
            $this->loadServiceRelations($visit->appointment);
        }

        return response()->json([
            'status' => true,
            'message' => 'Visit updated successfully.',
            'data' => $visit,
        ]);
    }

    /**
     * Delete a visit.
     */
    public function destroy(Visit $visit)
    {
        // Clear cache BEFORE deleting
        $this->clearAppointmentCaches($visit->appointment_id);
        // Note: We don't clear scheduler cache when deleting a visit because:
        // - The appointment still exists in the scheduler
        // - We're only removing the visit record, not the appointment
        // - The appointment status might revert, but that's handled by appointment cache clearing
        $visit->delete();

        return response()->json([
            'status' => true,
            'message' => 'Visit deleted successfully.',
        ]);
    }

    /**
     * Clear appointment caches when visits are created/updated/deleted
     */
    protected function clearAppointmentCaches($appointmentId = null)
    {
        $tenantId = tenant('id');
        $cache = app('cache')->store('database');

        // Clear base appointments cache
        $cache->forget("tenant_{$tenantId}_appointments");

        // Clear date-filtered appointment caches
        $today = now()->toDateString();
        $cache->forget("tenant_{$tenantId}_appointments_from_{$today}");
        $cache->forget("tenant_{$tenantId}_appointments_to_{$today}");
        $cache->forget("tenant_{$tenantId}_appointments_from_{$today}_to_{$today}");

        // Clear active appointments cache
        $cache->forget("tenant_{$tenantId}_active_appointments");

        // Clear specific appointment cache if ID provided
        if ($appointmentId) {
            $cache->forget("tenant_{$tenantId}_appointment_{$appointmentId}");
        }
    }
}
