<?php

namespace App\Http\Controllers;

use App\Models\Module;
use App\Models\Page;
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

        $pages = Page::whereHas('modules', fn ($q) => $q->whereIn('modules.id', $moduleIds))
            ->with(['modules' => fn ($q) => $q->whereIn('modules.id', $moduleIds)->orderByPivot('order')])
            ->get()
            ->flatMap(function ($page) use ($moduleIds) {
                return $page->modules->map(fn ($m) => array_merge($page->only(['id', 'name', 'code', 'path', 'description']), [
                    'order' => (int) $m->pivot->order,
                    'is_public' => (bool) $m->pivot->is_public,
                ]));
            })
            ->values()
            ->sortBy('order')
            ->values();

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
            ->with(['pages' => fn ($q) => $q->orderByPivot('order')])
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

        $isAssigned = $tenant->modules()
            ->where('modules.id', $moduleId)
            ->where('modules.active', true)
            ->exists();

        if (! $isAssigned) {
            return response()->json(['message' => 'Module not assigned or inactive'], 403);
        }

        $module = Module::findOrFail($moduleId);
        $pages = $module->pages()->orderByPivot('order')->get()->map(fn ($p) => array_merge($p->toArray(), [
            'order' => (int) $p->pivot->order,
            'is_public' => (bool) $p->pivot->is_public,
        ]));

        return response()->json([
            'pages' => $pages,
        ]);
    }

    public function getModulePages(int $moduleId): JsonResponse
    {
        $module = Module::findOrFail($moduleId);
        $pages = $module->pages()->orderByPivot('order')->get()->map(fn ($p) => array_merge($p->toArray(), [
            'order' => (int) $p->pivot->order,
            'is_public' => (bool) $p->pivot->is_public,
        ]));

        return response()->json([
            'pages' => $pages,
        ]);
    }
}
