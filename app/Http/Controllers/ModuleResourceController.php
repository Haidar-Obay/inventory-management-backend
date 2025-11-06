<?php

namespace App\Http\Controllers;

use App\Models\Module;
use App\Models\ModuleResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ModuleResourceController extends Controller
{
    public function index($moduleId): JsonResponse
    {
        $module = Module::findOrFail($moduleId);

        return response()->json([
            'resources' => $module->resources()->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, $moduleId): JsonResponse
    {
        $module = Module::findOrFail($moduleId);

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:100',
            'description' => 'nullable|string',
            'migration_class' => 'nullable|string|max:255',
            'enabled' => 'boolean',
            'version' => 'nullable|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if ($module->resources()->where('code', $request->code)->exists()) {
            return response()->json(['errors' => ['code' => ['Code must be unique per module']]], 422);
        }

        $nextId = $this->computeNextAvailableId(\App\Models\ModuleResource::class, 'id');
        $resource = new \App\Models\ModuleResource($request->only(['name', 'code', 'description', 'migration_class', 'enabled', 'version']));
        $resource->id = $nextId;
        $module->resources()->save($resource);

        return response()->json([
            'message' => 'Module resource created successfully',
            'resource' => $resource,
        ], 201);
    }

    public function show($moduleId, $resourceId): JsonResponse
    {
        $resource = ModuleResource::where('module_id', $moduleId)->findOrFail($resourceId);

        return response()->json(['resource' => $resource]);
    }

    public function update(Request $request, $moduleId, $resourceId): JsonResponse
    {
        $module = Module::findOrFail($moduleId);
        $resource = ModuleResource::where('module_id', $moduleId)->findOrFail($resourceId);

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'code' => 'sometimes|required|string|max:100',
            'description' => 'nullable|string',
            'migration_class' => 'nullable|string|max:255',
            'enabled' => 'boolean',
            'version' => 'nullable|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if ($request->filled('code') && $module->resources()->where('code', $request->code)->where('id', '!=', $resource->id)->exists()) {
            return response()->json(['errors' => ['code' => ['Code must be unique per module']]], 422);
        }

        $resource->update($request->only(['name', 'code', 'description', 'migration_class', 'enabled', 'version']));

        return response()->json([
            'message' => 'Module resource updated successfully',
            'resource' => $resource,
        ]);
    }

    public function destroy($moduleId, $resourceId): JsonResponse
    {
        $resource = ModuleResource::where('module_id', $moduleId)->findOrFail($resourceId);
        $resource->delete();

        return response()->json(['message' => 'Module resource deleted successfully']);
    }
}
