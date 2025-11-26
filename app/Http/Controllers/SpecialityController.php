<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSpecialityRequest;
use App\Http\Requests\UpdateSpecialityRequest;
use App\Models\Speciality;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SpecialityController extends Controller
{
    public function index(): JsonResponse
    {
        $specialities = Speciality::query()->orderBy('name')->get();

        return response()->json([
            'status' => true,
            'message' => 'Specialities fetched successfully.',
            'data' => $specialities,
        ]);
    }

    public function store(StoreSpecialityRequest $request): JsonResponse
    {
        $nextId = $this->computeNextAvailableId(Speciality::class, 'id');
        $speciality = new Speciality($request->validated());
        $speciality->id = $nextId;
        $speciality->save();

        return response()->json($speciality, 201);
    }

    public function show(Speciality $speciality): JsonResponse
    {
        return response()->json($speciality);
    }

    public function update(UpdateSpecialityRequest $request, Speciality $speciality): JsonResponse
    {
        $speciality->update($request->validated());

        return response()->json($speciality);
    }

    public function destroy(Speciality $speciality): JsonResponse
    {
        $identifier = $speciality->name ?? "ID: {$speciality->id}";
        $details = [];

        // Check if speciality has specialists
        if ($speciality->specialists()->exists()) {
            $specialistsCount = $speciality->specialists()->count();
            $details['specialists'] = [
                'count' => $specialistsCount,
                'sample_ids' => $speciality->specialists()->select('specialists.id')->limit(1)->pluck('id'),
            ];
        }

        if (! empty($details)) {
            return response()->json([
                'status' => false,
                'message' => "Cannot delete speciality \"{$identifier}\" (ID: {$speciality->id}). It is referenced by existing records.",
                'details' => $details,
            ], 409);
        }

        $speciality->delete();

        return response()->json([
            'status' => true,
            'message' => 'Speciality deleted successfully.',
        ]);
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
            'ids.*' => 'exists:specialities,id',
        ]);

        $skipped = [];
        $deleted = 0;

        foreach ($request->ids as $id) {
            try {
                $speciality = Speciality::find($id);

                if (! $speciality) {
                    $skipped[] = [
                        'id' => $id,
                        'name' => "ID: {$id}",
                        'reason' => 'Speciality not found.',
                    ];

                    continue;
                }

                $identifier = $speciality->name ?? "ID: {$id}";
                $details = [];

                // Check if speciality has specialists
                if ($speciality->specialists()->exists()) {
                    $specialistsCount = $speciality->specialists()->count();
                    $details['specialists'] = [
                        'count' => $specialistsCount,
                        'sample_ids' => $speciality->specialists()->select('specialists.id')->limit(1)->pluck('id'),
                    ];
                }

                if (! empty($details)) {
                    $skipped[] = [
                        'id' => $id,
                        'name' => $identifier,
                        'reason' => 'Cannot delete speciality. It is referenced by existing records.',
                        'details' => $details,
                    ];

                    continue;
                }

                $deleted += $speciality->delete();
            } catch (\Illuminate\Database\QueryException $e) {
                $speciality = Speciality::find($id);
                $identifier = $speciality?->name ?? "ID: {$id}";
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
