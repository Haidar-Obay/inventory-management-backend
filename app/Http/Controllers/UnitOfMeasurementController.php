<?php

namespace App\Http\Controllers;

use App\Http\Requests\UnitOfMeasurement\StoreUnitOfMeasurementRequest;
use App\Http\Requests\UnitOfMeasurement\UpdateUnitOfMeasurementRequest;
use App\Models\UnitOfMeasurement;

class UnitOfMeasurementController extends Controller
{
    public function index()
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_unit_of_measurements";

        $uoms = app('cache')->store('database')->get($key);

        if (! $uoms) {
            $uoms = UnitOfMeasurement::with('unitGroup')->orderBy('name')->get();
            app('cache')->store('database')->forever($key, $uoms);
        }

        return response()->json([
            'status' => true,
            'message' => 'Units of measurement fetched successfully.',
            'data' => $uoms,
        ]);
    }

    public function show(UnitOfMeasurement $unitOfMeasurement)
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_unit_of_measurement_{$unitOfMeasurement->id}";

        $cached = app('cache')->store('database')->get($key);

        if (! $cached) {
            $cached = $unitOfMeasurement->load('unitGroup');
            app('cache')->store('database')->forever($key, $cached);
        }

        return response()->json([
            'status' => true,
            'message' => 'Unit of measurement fetched successfully.',
            'data' => $cached,
        ]);
    }

    public function store(StoreUnitOfMeasurementRequest $request)
    {
        $uom = UnitOfMeasurement::create($request->validated());

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_unit_of_measurements");

        return response()->json([
            'status' => true,
            'message' => 'Unit of measurement created successfully.',
            'data' => $uom->load('unitGroup'),
        ], 201);
    }

    public function update(UpdateUnitOfMeasurementRequest $request, UnitOfMeasurement $unitOfMeasurement)
    {
        $unitOfMeasurement->update($request->validated());

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_unit_of_measurements");
        app('cache')->store('database')->forget("tenant_{$tenantId}_unit_of_measurement_{$unitOfMeasurement->id}");

        return response()->json([
            'status' => true,
            'message' => 'Unit of measurement updated successfully.',
            'data' => $unitOfMeasurement->load('unitGroup'),
        ]);
    }

    public function destroy(UnitOfMeasurement $unitOfMeasurement)
    {
        $tenantId = tenant('id');
        $unitOfMeasurement->delete();
        app('cache')->store('database')->forget("tenant_{$tenantId}_unit_of_measurements");
        app('cache')->store('database')->forget("tenant_{$tenantId}_unit_of_measurement_{$unitOfMeasurement->id}");

        return response()->json([
            'status' => true,
            'message' => 'Unit of measurement deleted successfully.',
        ]);
    }

    public function operations(UnitOfMeasurement $unitOfMeasurement)
    {
        return response()->json([
            'status' => true,
            'message' => 'Operations fetched successfully.',
            'data' => [
                ['name' => $unitOfMeasurement->operation],
            ],
        ]);
    }

    public function conversions(UnitOfMeasurement $unitOfMeasurement)
    {
        return response()->json([
            'status' => true,
            'message' => 'Conversions fetched successfully.',
            'data' => [
                [
                    'from' => $unitOfMeasurement->unitGroup?->name,
                    'to' => $unitOfMeasurement->name,
                    'factor' => $unitOfMeasurement->conversion,
                ],
            ],
        ]);
    }
}
