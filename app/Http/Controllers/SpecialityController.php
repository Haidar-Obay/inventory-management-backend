<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSpecialityRequest;
use App\Http\Requests\UpdateSpecialityRequest;
use App\Models\Speciality;
use Illuminate\Http\JsonResponse;

class SpecialityController extends Controller
{
    public function index(): JsonResponse
    {
        $specialities = Speciality::query()->orderBy('name');

        return response()->json($specialities);
    }

    public function store(StoreSpecialityRequest $request): JsonResponse
    {
        $speciality = Speciality::create($request->validated());

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
        $speciality->delete();

        return response()->json(['message' => 'Deleted']);
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
