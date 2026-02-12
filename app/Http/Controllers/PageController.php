<?php

namespace App\Http\Controllers;

use App\Models\Page;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PageController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'pages' => Page::orderBy('name')->get(),
        ]);
    }

    public function show($id): JsonResponse
    {
        $page = Page::findOrFail($id);

        return response()->json(['page' => $page]);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:100|unique:pages,code',
            'path' => 'required|string|max:255|unique:pages,path',
            'description' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $nextId = $this->computeNextAvailableId(Page::class, 'id');
        $page = new Page($request->only(['name', 'code', 'path', 'description']));
        $page->id = $nextId;
        $page->save();

        return response()->json([
            'message' => 'Page created successfully',
            'page' => $page,
        ], 201);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $page = Page::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'code' => 'sometimes|required|string|max:100|unique:pages,code,'.$id,
            'path' => 'sometimes|required|string|max:255|unique:pages,path,'.$id,
            'description' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $page->update($request->only(['name', 'code', 'path', 'description']));

        return response()->json([
            'message' => 'Page updated successfully',
            'page' => $page,
        ]);
    }

    public function destroy($id): JsonResponse
    {
        $page = Page::findOrFail($id);
        $page->delete();

        return response()->json(['message' => 'Page deleted successfully']);
    }
}
