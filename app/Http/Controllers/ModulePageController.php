<?php

namespace App\Http\Controllers;

use App\Models\Module;
use App\Models\Page;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ModulePageController extends Controller
{
    public function index($moduleId): JsonResponse
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

    public function store(Request $request, $moduleId): JsonResponse
    {
        $module = Module::findOrFail($moduleId);

        $validator = Validator::make($request->all(), [
            'page_id' => 'required|exists:pages,id',
            'order' => 'nullable|integer|min:0',
            'is_public' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if ($module->pages()->where('pages.id', $request->page_id)->exists()) {
            return response()->json(['errors' => ['page_id' => ['Page is already attached to this module']]], 422);
        }

        $module->pages()->attach($request->page_id, [
            'order' => $request->input('order', 0),
            'is_public' => $request->boolean('is_public', false),
        ]);

        $page = Page::find($request->page_id);
        $pivot = $module->pages()->where('pages.id', $request->page_id)->first()->pivot;

        return response()->json([
            'message' => 'Page attached to module successfully',
            'page' => array_merge($page->toArray(), [
                'order' => (int) $pivot->order,
                'is_public' => (bool) $pivot->is_public,
            ]),
        ], 201);
    }

    public function show($moduleId, $pageId): JsonResponse
    {
        $page = Module::findOrFail($moduleId)->pages()->where('pages.id', $pageId)->firstOrFail();
        $data = array_merge($page->toArray(), [
            'order' => (int) $page->pivot->order,
            'is_public' => (bool) $page->pivot->is_public,
        ]);

        return response()->json(['page' => $data]);
    }

    public function update(Request $request, $moduleId, $pageId): JsonResponse
    {
        $module = Module::findOrFail($moduleId);
        $module->pages()->where('pages.id', $pageId)->firstOrFail();

        $validator = Validator::make($request->all(), [
            'order' => 'nullable|integer|min:0',
            'is_public' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $module->pages()->updateExistingPivot($pageId, $request->only(['order', 'is_public']));

        $page = $module->pages()->where('pages.id', $pageId)->first();
        $data = array_merge($page->toArray(), [
            'order' => (int) $page->pivot->order,
            'is_public' => (bool) $page->pivot->is_public,
        ]);

        return response()->json([
            'message' => 'Module page updated successfully',
            'page' => $data,
        ]);
    }

    public function destroy($moduleId, $pageId): JsonResponse
    {
        $module = Module::findOrFail($moduleId);
        $module->pages()->detach($pageId);

        return response()->json(['message' => 'Page detached from module successfully']);
    }
}
