<?php

namespace App\Http\Controllers;

use App\Http\Requests\UnitGroup\StoreUnitGroupRequest;
use App\Http\Requests\UnitGroup\UpdateUnitGroupRequest;
use App\Models\UnitGroup;

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
        $unitGroup = UnitGroup::create($request->validated());

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
        $tenantId = tenant('id');
        $unitGroup->delete();
        app('cache')->store('database')->forget("tenant_{$tenantId}_unit_groups");
        app('cache')->store('database')->forget("tenant_{$tenantId}_unit_group_{$unitGroup->id}");

        return response()->json([
            'status' => true,
            'message' => 'Unit group deleted successfully.',
        ]);
    }
}
