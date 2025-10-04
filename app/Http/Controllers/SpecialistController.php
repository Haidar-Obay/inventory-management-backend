<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSpecialistRequest;
use App\Http\Requests\UpdateSpecialistRequest;
use App\Models\Specialist;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SpecialistController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Specialist::query()->with(['specialities:id,name', 'assets:id,name']);
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
        $specialists = $query->orderBy('name')->paginate();

        return response()->json($specialists);
    }

    public function store(StoreSpecialistRequest $request): JsonResponse
    {
        $data = $request->validated();
        $specialist = Specialist::create(['name' => $data['name']]);
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
        $specialist->update(['name' => $data['name']]);
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
        $specialist->delete();

        return response()->json(['message' => 'Deleted']);
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

    public function bulkDelete()
    { /* implement if needed later */
    }
}
