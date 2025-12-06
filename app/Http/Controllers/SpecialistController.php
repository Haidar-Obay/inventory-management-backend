<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSpecialistRequest;
use App\Http\Requests\UpdateSpecialistRequest;
use App\Models\Specialist;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SpecialistController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Specialist::query()->with(['specialities:id,name', 'assets:id,name', 'services:id,name']);
        if ($request->filled('speciality_id')) {
            $query->whereHas('specialities', function ($q) use ($request) {
                $q->where('speciality_id', $request->integer('speciality_id'));
            });
        }
        if ($request->filled('asset_id')) {
            $query->whereHas('assets', function ($q) use ($request) {
                $q->where('asset_id', $request->integer('asset_id'));
            });
        }
        if ($request->filled('service_id')) {
            $query->whereHas('services', function ($q) use ($request) {
                $q->where('service_id', $request->integer('service_id'));
            });
        }
        $specialists = $query->orderBy('name')->get();

        return response()->json([
            'status' => true,
            'message' => 'Specialists fetched successfully.',
            'data' => $specialists,
        ]);
    }

    public function store(StoreSpecialistRequest $request): JsonResponse
    {
        $data = $request->validated();
        $nextId = $this->computeNextAvailableId(Specialist::class, 'id');
        $specialist = new Specialist([
            'name' => $data['name'],
            'capacity_per_hour' => $data['capacity_per_hour'] ?? null,
            'capacity_per_day' => $data['capacity_per_day'] ?? null,
            'phone_1' => $data['phone_1'] ?? null,
            'phone_2' => $data['phone_2'] ?? null,
            'address' => $data['address'] ?? null,
            'email' => $data['email'] ?? null,
        ]);
        $specialist->id = $nextId;
        $specialist->save();
        if (! empty($data['speciality_ids'])) {
            $specialist->specialities()->sync($data['speciality_ids']);
        }
        if (! empty($data['asset_ids'])) {
            $specialist->assets()->sync($data['asset_ids']);
        }

        return response()->json($specialist->load(['specialities:id,name', 'assets:id,name']), 201);
    }

    public function show(Specialist $specialist): JsonResponse
    {
        return response()->json($specialist->load(['specialities:id,name', 'assets:id,name']));
    }

    public function update(UpdateSpecialistRequest $request, Specialist $specialist): JsonResponse
    {
        $data = $request->validated();
        $specialist->update([
            'name' => $data['name'],
            'capacity_per_hour' => $data['capacity_per_hour'] ?? null,
            'capacity_per_day' => $data['capacity_per_day'] ?? null,
            'phone_1' => $data['phone_1'] ?? null,
            'phone_2' => $data['phone_2'] ?? null,
            'address' => $data['address'] ?? null,
            'email' => $data['email'] ?? null,
        ]);
        if (array_key_exists('speciality_ids', $data)) {
            $specialist->specialities()->sync($data['speciality_ids'] ?? []);
        }
        if (array_key_exists('asset_ids', $data)) {
            $specialist->assets()->sync($data['asset_ids'] ?? []);
        }

        return response()->json($specialist->load(['specialities:id,name', 'assets:id,name']));
    }

    public function destroy(Specialist $specialist): JsonResponse
    {
        $identifier = $specialist->name ?? "ID: {$specialist->id}";
        $details = [];

        // Check if specialist has appointments through pivot table
        $appointmentsCount = DB::table('appointment_service')
            ->where('specialist_id', $specialist->id)
            ->whereNotNull('specialist_id')
            ->distinct('appointment_id')
            ->count('appointment_id');

        if ($appointmentsCount > 0) {
            $sampleAppointmentId = DB::table('appointment_service')
                ->where('specialist_id', $specialist->id)
                ->whereNotNull('specialist_id')
                ->select('appointment_id')
                ->first()?->appointment_id;

            $details['appointments'] = [
                'count' => $appointmentsCount,
                'sample_ids' => $sampleAppointmentId ? [$sampleAppointmentId] : [],
            ];
        }

        // Check if specialist has services
        if ($specialist->services()->exists()) {
            $servicesCount = $specialist->services()->count();
            $details['services'] = [
                'count' => $servicesCount,
                'sample_ids' => $specialist->services()->select('services.id')->limit(1)->pluck('id'),
            ];
        }

        if (! empty($details)) {
            return response()->json([
                'status' => false,
                'message' => "Cannot delete specialist \"{$identifier}\" (ID: {$specialist->id}). It is referenced by existing records.",
                'details' => $details,
            ], 409);
        }

        $specialist->delete();

        return response()->json([
            'status' => true,
            'message' => 'Specialist deleted successfully.',
        ]);
    }

    public function attachSpecialities(Specialist $specialist, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer', 'exists:specialities,id'],
        ]);
        $specialist->specialities()->syncWithoutDetaching($validated['ids']);

        return response()->json($specialist->load('specialities'));
    }

    public function detachSpecialities(Specialist $specialist, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer', 'exists:specialities,id'],
        ]);
        $specialist->specialities()->detach($validated['ids']);

        return response()->json($specialist->load('specialities'));
    }

    public function attachAssets(Specialist $specialist, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer', 'exists:assets,id'],
        ]);
        $specialist->assets()->syncWithoutDetaching($validated['ids']);

        return response()->json($specialist->load('assets'));
    }

    public function detachAssets(Specialist $specialist, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer', 'exists:assets,id'],
        ]);
        $specialist->assets()->detach($validated['ids']);

        return response()->json($specialist->load('assets'));
    }

    public function exportExcell()
    { /* implement if needed later */
    }

    public function exportPdf()
    { /* implement if needed later */
    }

    public function importFromExcel()
    { /* implement if needed later */
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:specialists,id',
        ]);

        $skipped = [];
        $deleted = 0;

        foreach ($request->ids as $id) {
            try {
                $specialist = Specialist::find($id);

                if (! $specialist) {
                    $skipped[] = [
                        'id' => $id,
                        'name' => "ID: {$id}",
                        'reason' => 'Specialist not found.',
                    ];

                    continue;
                }

                $identifier = $specialist->name ?? "ID: {$id}";
                $details = [];

                // Check if specialist has appointments through pivot table
                $appointmentsCount = DB::table('appointment_service')
                    ->where('specialist_id', $specialist->id)
                    ->whereNotNull('specialist_id')
                    ->distinct('appointment_id')
                    ->count('appointment_id');

                if ($appointmentsCount > 0) {
                    $sampleAppointmentId = DB::table('appointment_service')
                        ->where('specialist_id', $specialist->id)
                        ->whereNotNull('specialist_id')
                        ->select('appointment_id')
                        ->first()?->appointment_id;

                    $details['appointments'] = [
                        'count' => $appointmentsCount,
                        'sample_ids' => $sampleAppointmentId ? [$sampleAppointmentId] : [],
                    ];
                }

                // Check if specialist has services
                if ($specialist->services()->exists()) {
                    $servicesCount = $specialist->services()->count();
                    $details['services'] = [
                        'count' => $servicesCount,
                        'sample_ids' => $specialist->services()->select('services.id')->limit(1)->pluck('id'),
                    ];
                }

                if (! empty($details)) {
                    $skipped[] = [
                        'id' => $id,
                        'name' => $identifier,
                        'reason' => 'Cannot delete specialist. It is referenced by existing records.',
                        'details' => $details,
                    ];

                    continue;
                }

                $deleted += $specialist->delete();
            } catch (\Illuminate\Database\QueryException $e) {
                $specialist = Specialist::find($id);
                $identifier = $specialist?->name ?? "ID: {$id}";
                $skipped[] = [
                    'id' => $id,
                    'name' => $identifier,
                    'reason' => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'status' => true,
            'message' => 'Bulk delete completed.',
            'data' => [
                'deleted_count' => $deleted,
                'skipped' => $skipped,
            ],
        ]);
    }
}
