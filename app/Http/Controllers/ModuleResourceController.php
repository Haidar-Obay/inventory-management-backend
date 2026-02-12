<?php

namespace App\Http\Controllers;

use App\Models\Module;
use App\Models\Resource;
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
            'resource_id' => 'required|exists:resources,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if ($module->resources()->where('resources.id', $request->resource_id)->exists()) {
            return response()->json(['errors' => ['resource_id' => ['Resource is already attached to this module']]], 422);
        }

        $module->resources()->attach($request->resource_id);
        $resource = Resource::find($request->resource_id);

        return response()->json([
            'message' => 'Resource attached to module successfully',
            'resource' => $resource,
        ], 201);
    }

    public function show($moduleId, $resourceId): JsonResponse
    {
        $resource = Module::findOrFail($moduleId)->resources()->where('resources.id', $resourceId)->firstOrFail();

        return response()->json(['resource' => $resource]);
    }

    public function update(Request $request, $moduleId, $resourceId): JsonResponse
    {
        Module::findOrFail($moduleId)->resources()->where('resources.id', $resourceId)->firstOrFail();
        // Pivot has no extra columns; no-op or update the resource itself via ResourceController
        return response()->json([
            'message' => 'Module resource link updated successfully',
        ]);
    }

    public function destroy($moduleId, $resourceId): JsonResponse
    {
        $module = Module::findOrFail($moduleId);
        $module->resources()->detach($resourceId);

        return response()->json(['message' => 'Resource detached from module successfully']);
    }
}
