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
        $data = $request->validated();
        $nextId = $this->computeNextAvailableId(UnitOfMeasurement::class, 'id');
        $uom = new UnitOfMeasurement($data);
        $uom->id = $nextId;
        $uom->save();

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
        // Prevent deletion if referenced by items (via pivot or direct foreign keys)
        $pivotItemIds = $unitOfMeasurement->items()->pluck('items.id');
        $directItemIds = \App\Models\Item::where(function ($query) use ($unitOfMeasurement) {
            $query->where('base_uom_id', $unitOfMeasurement->id)
                  ->orWhere('purchase_uom_id', $unitOfMeasurement->id)
                  ->orWhere('sales_uom_id', $unitOfMeasurement->id);
        })->pluck('items.id');
        $allItemIds = $pivotItemIds->merge($directItemIds)->unique();
        $totalItemCount = $allItemIds->count();

        if ($totalItemCount > 0) {
            $sampleIds = $allItemIds->take(1)->values()->all();

            return response()->json([
                'status' => false,
                'message' => 'Cannot delete unit of measurement. It is referenced by existing items.',
                'details' => [
                    'items' => [
                        'count' => $totalItemCount,
                        'sample_ids' => $sampleIds,
                    ],
                ],
            ], 409);
        }

        $tenantId = tenant('id');
        $unitOfMeasurement->delete();
        app('cache')->store('database')->forget("tenant_{$tenantId}_unit_of_measurements");
        app('cache')->store('database')->forget("tenant_{$tenantId}_unit_of_measurement_{$unitOfMeasurement->id}");

        return response()->json([
            'status' => true,
            'message' => 'Unit of measurement deleted successfully.',
        ]);
    }
}
