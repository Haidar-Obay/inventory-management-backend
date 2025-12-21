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
            'service',
            'specialist',
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
            'service_id' => 'nullable|exists:services,id',
            'specialist_id' => 'nullable|exists:specialists,id',
            'notes' => 'nullable|string|max:1000',
            'arrived_at' => 'nullable|date',
        ]);

        $visit = new Visit;
        $visit->customer_id = $data['customer_id'];
        $visit->appointment_id = $data['appointment_id'] ?? null;
        $visit->status = 'arrived';
        $visit->arrived_at = isset($data['arrived_at']) ? $data['arrived_at'] : now();
        $visit->notes = $data['notes'] ?? null;

        // Handle service and specialist
        // If visit is from appointment, inherit from appointment (first service/specialist)
        // Otherwise, use provided values (for walk-ins)
        if ($visit->appointment_id) {
            // Load appointment to get services
            $appointment = \App\Models\Appointment::with('services')->find($visit->appointment_id);
            if ($appointment && $appointment->services->isNotEmpty()) {
                $firstService = $appointment->services->first();
                $visit->service_id = $data['service_id'] ?? $firstService->id;
                // Get specialist from pivot if available
                $visit->specialist_id = $data['specialist_id'] ?? $firstService->pivot->specialist_id ?? null;
            } else {
                // No services in appointment, use provided values
                $visit->service_id = $data['service_id'] ?? null;
                $visit->specialist_id = $data['specialist_id'] ?? null;
            }
        } else {
            // Walk-in: use provided values
            $visit->service_id = $data['service_id'] ?? null;
            $visit->specialist_id = $data['specialist_id'] ?? null;
        }

        $visit->save();

        // Optionally, we could sync appointment status here (usually remains "active" on arrival)
        // $visit->applyStatusToAppointment();

        $visit->load([
            'customer',
            'appointment.customers',
            'appointment.services',
            'service',
            'specialist',
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
            'service',
            'specialist',
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
            'customer_id' => 'nullable|exists:customers,id',
            'status' => 'nullable|in:arrived,in_progress,completed,cancelled',
            'service_id' => 'nullable|exists:services,id',
            'specialist_id' => 'nullable|exists:specialists,id',
            'notes' => 'nullable|string|max:1000',
            'cancellation_reason' => 'nullable|string|max:1000',
        ]);

        $originalStatus = $visit->status;

        if (array_key_exists('customer_id', $data)) {
            $visit->customer_id = $data['customer_id'];
        }

        if (array_key_exists('notes', $data)) {
            $visit->notes = $data['notes'];
        }

        if (array_key_exists('cancellation_reason', $data)) {
            $visit->cancellation_reason = $data['cancellation_reason'];
        }

        if (array_key_exists('service_id', $data)) {
            $visit->service_id = $data['service_id'];
        }

        if (array_key_exists('specialist_id', $data)) {
            $visit->specialist_id = $data['specialist_id'];
        }

        if (! empty($data['status'])) {
            $visit->status = $data['status'];
        }

        $visit->save();

        // If status changed, propagate to appointment
        if ($originalStatus !== $visit->status) {
            $visit->load('appointment'); // ensure relation is loaded
            $visit->applyStatusToAppointment();
        }

        $visit->load([
            'customer',
            'appointment.customers',
            'appointment.services',
            'service',
            'specialist',
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
        $visit->delete();

        return response()->json([
            'status' => true,
            'message' => 'Visit deleted successfully.',
        ]);
    }
}
