<?php

namespace App\Http\Controllers;

use App\Models\Module;
use App\Models\ModulePage;
use Illuminate\Http\JsonResponse;

class TenantModuleController extends Controller
{
    public function getAllowedPages(): JsonResponse
    {
        $tenant = tenant();
        if (! $tenant) {
            return response()->json(['message' => 'Tenant not found'], 404);
        }

        $moduleIds = $tenant->modules()->where('modules.active', true)->pluck('modules.id');

        $pages = ModulePage::whereIn('module_id', $moduleIds)
            ->orderBy('order')
            ->get();

        return response()->json([
            'pages' => $pages,
        ]);
    }

    public function getAssignedModules(): JsonResponse
    {
        $tenant = tenant();
        if (! $tenant) {
            return response()->json(['message' => 'Tenant not found'], 404);
        }

        $modules = $tenant->modules()
            ->where('modules.active', true)
            ->with(['pages' => function ($q) {
                $q->orderBy('order');
            }])
            ->orderBy('modules.sort_order')
            ->get(['modules.id', 'modules.name', 'modules.code', 'modules.icon', 'modules.active', 'modules.sort_order']);

        return response()->json([
            'count' => $modules->count(),
            'modules' => $modules,
        ]);
    }

    public function getAllowedPagesForModule(int $moduleId): JsonResponse
    {
        $tenant = tenant();
        if (! $tenant) {
            return response()->json(['message' => 'Tenant not found'], 404);
        }

        // Ensure module is assigned to tenant and active
        $isAssigned = $tenant->modules()
            ->where('modules.id', $moduleId)
            ->where('modules.active', true)
            ->exists();

        if (! $isAssigned) {
            return response()->json(['message' => 'Module not assigned or inactive'], 403);
        }

        $pages = ModulePage::where('module_id', $moduleId)
            ->orderBy('order')
            ->get();

        return response()->json([
            'pages' => $pages,
        ]);
    }

    public function getModulePages(int $moduleId): JsonResponse
    {
        // Simple method to get pages for a module without tenant validation
        $pages = ModulePage::where('module_id', $moduleId)
            ->orderBy('order')
            ->get();

        return response()->json([
            'pages' => $pages,
        ]);
    }
}
