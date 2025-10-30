<?php

namespace App\Http\Controllers;

use App\Models\Module;
use App\Models\ModulePage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ModulePageController extends Controller
{
    public function index($moduleId): JsonResponse
    {
        $module = Module::findOrFail($moduleId);

        return response()->json([
            'pages' => $module->pages()->orderBy('order')->get(),
        ]);
    }

    public function store(Request $request, $moduleId): JsonResponse
    {
        $module = Module::findOrFail($moduleId);

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:100',
            'path' => 'required|string|max:255',
            'order' => 'nullable|integer|min:0',
            'is_public' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Enforce uniqueness per module
        if ($module->pages()->where('code', $request->code)->exists()) {
            return response()->json(['errors' => ['code' => ['Code must be unique per module']]], 422);
        }
        if ($module->pages()->where('path', $request->path)->exists()) {
            return response()->json(['errors' => ['path' => ['Path must be unique per module']]], 422);
        }

        $nextId = $this->computeNextAvailableId(\App\Models\ModulePage::class, 'id');
        $page = new \App\Models\ModulePage($request->only(['name', 'code', 'path', 'order', 'is_public']));
        $page->id = $nextId;
        $module->pages()->save($page);

        return response()->json([
            'message' => 'Module page created successfully',
            'page' => $page,
        ], 201);
    }

    public function show($moduleId, $pageId): JsonResponse
    {
        $page = ModulePage::where('module_id', $moduleId)->findOrFail($pageId);

        return response()->json(['page' => $page]);
    }

    public function update(Request $request, $moduleId, $pageId): JsonResponse
    {
        $module = Module::findOrFail($moduleId);
        $page = ModulePage::where('module_id', $moduleId)->findOrFail($pageId);

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'code' => 'sometimes|required|string|max:100',
            'path' => 'sometimes|required|string|max:255',
            'order' => 'nullable|integer|min:0',
            'is_public' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if ($request->filled('code') && $module->pages()->where('code', $request->code)->where('id', '!=', $page->id)->exists()) {
            return response()->json(['errors' => ['code' => ['Code must be unique per module']]], 422);
        }
        if ($request->filled('path') && $module->pages()->where('path', $request->path)->where('id', '!=', $page->id)->exists()) {
            return response()->json(['errors' => ['path' => ['Path must be unique per module']]], 422);
        }

        $page->update($request->only(['name', 'code', 'path', 'order', 'is_public']));

        return response()->json([
            'message' => 'Module page updated successfully',
            'page' => $page,
        ]);
    }

    public function destroy($moduleId, $pageId): JsonResponse
    {
        $page = ModulePage::where('module_id', $moduleId)->findOrFail($pageId);
        $page->delete();

        return response()->json(['message' => 'Module page deleted successfully']);
    }
}
