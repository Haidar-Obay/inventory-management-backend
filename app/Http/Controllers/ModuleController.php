<?php

namespace App\Http\Controllers;

use App\Models\Module;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

class ModuleController extends Controller
{
    public function index(): JsonResponse
    {
        $cacheKey = 'modules_all';
        $modules = Cache::remember($cacheKey, 3600, function () {
            return Module::active()->ordered()->get();
        });

        return response()->json([
            'modules' => $modules,
        ]);
    }

    public function show($id): JsonResponse
    {
        $module = Module::findOrFail($id);

        return response()->json([
            'module' => $module,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50|unique:modules,code',
            'description' => 'nullable|string',
            'icon' => 'nullable|string|max:100',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors(),
            ], 422);
        }

        $module = Module::create($request->all());

        Cache::forget('modules_all');

        return response()->json([
            'message' => 'Module created successfully',
            'module' => $module,
        ], 201);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $module = Module::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'code' => 'sometimes|required|string|max:50|unique:modules,code,'.$id,
            'description' => 'nullable|string',
            'icon' => 'nullable|string|max:100',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors(),
            ], 422);
        }

        $module->update($request->all());

        Cache::forget('modules_all');

        return response()->json([
            'message' => 'Module updated successfully',
            'module' => $module,
        ]);
    }

    public function destroy($id): JsonResponse
    {
        $module = Module::findOrFail($id);

        // Check if module is being used by any subscription plans
        if ($module->subscriptionPlans()->exists()) {
            return response()->json([
                'message' => 'Cannot delete module. It is currently being used by subscription plans.',
            ], 422);
        }

        $module->delete();

        Cache::forget('modules_all');

        return response()->json([
            'message' => 'Module deleted successfully',
        ]);
    }

    /**
     * Get modules with their usage statistics
     */
    public function getUsageStats(): JsonResponse
    {
        $modules = Module::withCount('subscriptionPlans')->get();

        return response()->json([
            'modules' => $modules,
        ]);
    }
}