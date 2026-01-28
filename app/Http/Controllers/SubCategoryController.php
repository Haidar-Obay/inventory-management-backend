<?php

namespace App\Http\Controllers;

use App\Models\SubCategory;
use Illuminate\Http\Request;

class SubCategoryController extends Controller
{
    public function index()
    {
        $subCategories = SubCategory::with('category')->orderBy('category_id')->orderBy('name')->get();

        return response()->json([
            'status' => true,
            'message' => 'Sub categories fetched successfully.',
            'data' => $subCategories,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'category_id' => 'required|exists:categories,id',
        ]);

        if (SubCategory::where('category_id', $request->category_id)->where('name', $request->name)->exists()) {
            return response()->json(['message' => 'A subcategory with this name already exists for the selected category'], 422);
        }

        $nextId = $this->computeNextAvailableId(SubCategory::class, 'id');
        $subCategory = new SubCategory($request->only(['name', 'category_id']));
        $subCategory->id = $nextId;
        $subCategory->save();

        $subCategory->load('category');

        return response()->json([
            'status' => true,
            'message' => 'Sub category created successfully.',
            'data' => $subCategory,
        ]);
    }

    public function show(SubCategory $subCategory)
    {
        $subCategory->load('category');

        return response()->json([
            'status' => true,
            'message' => 'Sub category fetched successfully.',
            'data' => $subCategory,
        ]);
    }

    public function update(Request $request, SubCategory $subCategory)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'category_id' => 'required|exists:categories,id',
        ]);

        $exists = SubCategory::where('category_id', $request->category_id)
            ->where('name', $request->name)
            ->where('id', '!=', $subCategory->id)
            ->exists();
        if ($exists) {
            return response()->json(['message' => 'A subcategory with this name already exists for the selected category'], 422);
        }

        $subCategory->update($request->only(['name', 'category_id']));
        $subCategory->load('category');

        return response()->json([
            'status' => true,
            'message' => 'Sub category updated successfully.',
            'data' => $subCategory,
        ]);
    }

    public function destroy(SubCategory $subCategory)
    {
        $subCategory->delete();

        return response()->json([
            'status' => true,
            'message' => 'Sub category deleted successfully.',
        ]);
    }

    public function bulkDelete(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:sub_categories,id',
        ]);

        SubCategory::whereIn('id', $request->ids)->delete();

        return response()->json([
            'message' => 'Bulk delete completed.',
            'deleted_count' => count($request->ids),
        ]);
    }
}
