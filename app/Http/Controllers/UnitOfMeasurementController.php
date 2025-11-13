<?php

namespace App\Http\Controllers;

use App\Http\Requests\UnitOfMeasurement\StoreUnitOfMeasurementRequest;
use App\Http\Requests\UnitOfMeasurement\UpdateUnitOfMeasurementRequest;
use App\Models\UnitOfMeasurement;
use Illuminate\Http\Request;

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

            $identifier = $unitOfMeasurement->name ?? $unitOfMeasurement->code ?? "ID: {$unitOfMeasurement->id}";

            return response()->json([
                'status' => false,
                'message' => "Cannot delete unit of measurement \"{$identifier}\" (ID: {$unitOfMeasurement->id}). It is referenced by existing items.",
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

    public function operations(UnitOfMeasurement $unitOfMeasurement)
    {
        // Operation and conversion are now stored in item_unit_of_measurement pivot
        // Return empty or placeholder data
        return response()->json([
            'status' => true,
            'message' => 'Operations fetched successfully.',
            'data' => [],
        ]);
    }

    public function conversions(UnitOfMeasurement $unitOfMeasurement)
    {
        // Operation and conversion are now stored in item_unit_of_measurement pivot
        // Return empty or placeholder data
        return response()->json([
            'status' => true,
            'message' => 'Conversions fetched successfully.',
            'data' => [],
        ]);
    }

    public function bulkDelete(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:unit_of_measurements,id',
        ]);

        $ids = $request->input('ids');
        $skipped = [];
        $deleted = 0;

        foreach ($ids as $id) {
            try {
                $unitOfMeasurement = UnitOfMeasurement::find($id);

                if (! $unitOfMeasurement) {
                    $skipped[] = [
                        'id' => $id,
                        'reason' => 'Unit of measurement not found.',
                    ];
                    continue;
                }

                // Check if referenced by items (via pivot or direct foreign keys)
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
                    $identifier = $unitOfMeasurement->name ?? "ID: {$id}";
                    $skipped[] = [
                        'id' => $id,
                        'name' => $identifier,
                        'reason' => 'Cannot delete unit of measurement. It is referenced by existing items.',
                        'details' => [
                            'items' => [
                                'count' => $totalItemCount,
                                'sample_ids' => $sampleIds,
                            ],
                        ],
                    ];
                    continue;
                }

                $unitOfMeasurement->delete();
                $deleted++;

            } catch (\Illuminate\Database\QueryException $e) {
                $unitOfMeasurement = UnitOfMeasurement::find($id);
                $identifier = $unitOfMeasurement?->name ?? "ID: {$id}";
                $skipped[] = [
                    'id' => $id,
                    'name' => $identifier,
                    'reason' => $e->getMessage(),
                ];
            } catch (\Exception $e) {
                $unitOfMeasurement = UnitOfMeasurement::find($id);
                $identifier = $unitOfMeasurement?->name ?? "ID: {$id}";
                $skipped[] = [
                    'id' => $id,
                    'name' => $identifier,
                    'reason' => $e->getMessage(),
                ];
            }
        }

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_unit_of_measurements");

        return response()->json([
            'message' => 'Bulk delete completed.',
            'deleted_count' => $deleted,
            'skipped' => $skipped,
        ]);
    }
}
