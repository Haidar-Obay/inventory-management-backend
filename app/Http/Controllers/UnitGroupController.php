<?php

namespace App\Http\Controllers;

use App\Http\Requests\UnitGroup\StoreUnitGroupRequest;
use App\Http\Requests\UnitGroup\UpdateUnitGroupRequest;
use App\Models\UnitGroup;
use Illuminate\Http\Request;

class UnitGroupController extends Controller
{
    public function index()
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_unit_groups";

        $unitGroups = app('cache')->store('database')->get($key);

        if (! $unitGroups) {
            $unitGroups = UnitGroup::with('unitOfMeasurements')->orderBy('name')->get();
            app('cache')->store('database')->forever($key, $unitGroups);
        }

        return response()->json([
            'status' => true,
            'message' => 'Unit groups fetched successfully.',
            'data' => $unitGroups,
        ]);
    }

    public function show(UnitGroup $unitGroup)
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_unit_group_{$unitGroup->id}";

        $cached = app('cache')->store('database')->get($key);

        if (! $cached) {
            $cached = $unitGroup->load('unitOfMeasurements');
            app('cache')->store('database')->forever($key, $cached);
        }

        return response()->json([
            'status' => true,
            'message' => 'Unit group fetched successfully.',
            'data' => $cached,
        ]);
    }

    public function store(StoreUnitGroupRequest $request)
    {
        $data = $request->validated();
        $nextId = $this->computeNextAvailableId(UnitGroup::class, 'id');
        $unitGroup = new UnitGroup($data);
        $unitGroup->id = $nextId;
        $unitGroup->save();

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_unit_groups");

        return response()->json([
            'status' => true,
            'message' => 'Unit group created successfully.',
            'data' => $unitGroup,
        ], 201);
    }

    public function update(UpdateUnitGroupRequest $request, UnitGroup $unitGroup)
    {
        $unitGroup->update($request->validated());

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_unit_groups");
        app('cache')->store('database')->forget("tenant_{$tenantId}_unit_group_{$unitGroup->id}");

        return response()->json([
            'status' => true,
            'message' => 'Unit group updated successfully.',
            'data' => $unitGroup,
        ]);
    }

    public function destroy(UnitGroup $unitGroup)
    {
        // Prevent deletion if related unit of measurements exist; include helpful details
        if ($unitGroup->unitOfMeasurements()->exists()) {
            $count = $unitGroup->unitOfMeasurements()->count();
            $sampleIds = $unitGroup->unitOfMeasurements()->select('unit_of_measurements.id')->limit(1)->pluck('id');

            $identifier = $unitGroup->name ?? $unitGroup->code ?? "ID: {$unitGroup->id}";

            return response()->json([
                'status' => false,
                'message' => "Cannot delete unit group \"{$identifier}\" (ID: {$unitGroup->id}). It is referenced by existing unit of measurements.",
                'details' => [
                    'unit_of_measurements' => [
                        'count' => $count,
                        'sample_ids' => $sampleIds,
                    ],
                ],
            ], 409);
        }

        $tenantId = tenant('id');
        $unitGroup->delete();
        app('cache')->store('database')->forget("tenant_{$tenantId}_unit_groups");
        app('cache')->store('database')->forget("tenant_{$tenantId}_unit_group_{$unitGroup->id}");

        return response()->json([
            'status' => true,
            'message' => 'Unit group deleted successfully.',
        ]);
    }

    public function units(UnitGroup $unitGroup)
    {
        $units = $unitGroup->unitOfMeasurements()->orderBy('name')->get();

        return response()->json([
            'status' => true,
            'message' => 'Units fetched successfully.',
            'data' => $units,
        ]);
    }

    public function bulkDelete(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:unit_groups,id',
        ]);

        $ids = $request->input('ids');
        $skipped = [];
        $deleted = 0;

        foreach ($ids as $id) {
            try {
                $unitGroup = UnitGroup::find($id);

                if (! $unitGroup) {
                    $skipped[] = [
                        'id' => $id,
                        'reason' => 'Unit group not found.',
                    ];

                    continue;
                }

                // Check if unit group has unit of measurements
                if ($unitGroup->unitOfMeasurements()->exists()) {
                    $count = $unitGroup->unitOfMeasurements()->count();
                    $sampleIds = $unitGroup->unitOfMeasurements()->select('unit_of_measurements.id')->limit(1)->pluck('id');

                    $identifier = $unitGroup->name ?? "ID: {$id}";
                    $skipped[] = [
                        'id' => $id,
                        'name' => $identifier,
                        'reason' => 'Cannot delete unit group. It is referenced by existing unit of measurements.',
                        'details' => [
                            'unit_of_measurements' => [
                                'count' => $count,
                                'sample_ids' => $sampleIds,
                            ],
                        ],
                    ];

                    continue;
                }

                $unitGroup->delete();
                $deleted++;

            } catch (\Illuminate\Database\QueryException $e) {
                $unitGroup = UnitGroup::find($id);
                $identifier = $unitGroup?->name ?? "ID: {$id}";
                $skipped[] = [
                    'id' => $id,
                    'name' => $identifier,
                    'reason' => $e->getMessage(),
                ];
            } catch (\Exception $e) {
                $unitGroup = UnitGroup::find($id);
                $identifier = $unitGroup?->name ?? "ID: {$id}";
                $skipped[] = [
                    'id' => $id,
                    'name' => $identifier,
                    'reason' => $e->getMessage(),
                ];
            }
        }

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_unit_groups");

        return response()->json([
            'message' => 'Bulk delete completed.',
            'deleted_count' => $deleted,
            'skipped' => $skipped,
        ]);
    }
}
