<?php

namespace App\Http\Controllers;

use App\Models\Resource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ResourceController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'resources' => Resource::orderBy('name')->get(),
        ]);
    }

    public function show($id): JsonResponse
    {
        $resource = Resource::findOrFail($id);

        return response()->json(['resource' => $resource]);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:100|unique:resources,code',
            'description' => 'nullable|string',
            'migration_class' => 'nullable|string|max:255',
            'enabled' => 'boolean',
            'version' => 'nullable|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $nextId = $this->computeNextAvailableId(Resource::class, 'id');
        $resource = new Resource($request->only(['name', 'code', 'description', 'migration_class', 'enabled', 'version']));
        $resource->id = $nextId;
        $resource->save();

        return response()->json([
            'message' => 'Resource created successfully',
            'resource' => $resource,
        ], 201);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $resource = Resource::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'code' => 'sometimes|required|string|max:100|unique:resources,code,'.$id,
            'description' => 'nullable|string',
            'migration_class' => 'nullable|string|max:255',
            'enabled' => 'boolean',
            'version' => 'nullable|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $resource->update($request->only(['name', 'code', 'description', 'migration_class', 'enabled', 'version']));

        return response()->json([
            'message' => 'Resource updated successfully',
            'resource' => $resource,
        ]);
    }

    public function destroy($id): JsonResponse
    {
        $resource = Resource::findOrFail($id);
        $resource->delete();

        return response()->json(['message' => 'Resource deleted successfully']);
    }
}
