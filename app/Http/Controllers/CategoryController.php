<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Imports\DynamicExcelImport;

class CategoryController extends Controller
{
    public function index()
    {
        $tenantId = tenant('id');
        $cacheKey = "categories_{$tenantId}";

        return Cache::remember($cacheKey, 3600, function () {
            return Category::with('parentCategory')->get();
        });
    }

    public function store(Request $request)
    {
        $request->validate([
            'code' => 'required|string|unique:categories,code',
            'name' => 'required|string',
            'subcategory_of' => 'nullable|exists:categories,id',
            'is_inactive' => 'boolean',
        ]);

        // Prevent circular references
        if ($request->subcategory_of) {
            $parentCategory = Category::find($request->subcategory_of);
            if ($parentCategory->subcategory_of === $request->subcategory_of) {
                return response()->json(['message' => 'Circular reference detected in category hierarchy'], 422);
            }
        }

        $category = Category::create($request->all());
        Cache::forget("categories_" . tenant('id'));

        return response()->json($category, 201);
    }

    public function show(Category $category)
    {
        $tenantId = tenant('id');
        $cacheKey = "category_{$category->id}_{$tenantId}";

        return Cache::remember($cacheKey, 3600, function () use ($category) {
            return $category->load('parentCategory', 'subcategories');
        });
    }

    public function update(Request $request, Category $category)
    {
        $request->validate([
            'code' => 'required|string|unique:categories,code,' . $category->id,
            'name' => 'required|string',
            'subcategory_of' => 'nullable|exists:categories,id',
            'is_inactive' => 'boolean',
        ]);

        // Prevent circular references
        if ($request->subcategory_of) {
            if ($request->subcategory_of === $category->id) {
                return response()->json(['message' => 'A category cannot be a subcategory of itself'], 422);
            }

            $parentCategory = Category::find($request->subcategory_of);
            if ($parentCategory->subcategory_of === $category->id) {
                return response()->json(['message' => 'Circular reference detected in category hierarchy'], 422);
            }
        }

        $category->update($request->all());
        Cache::forget("categories_" . tenant('id'));
        Cache::forget("category_{$category->id}_" . tenant('id'));

        return response()->json($category);
    }

    public function destroy(Category $category)
    {
        // Check if category has subcategories
        if ($category->subcategories()->exists()) {
            return response()->json(['message' => 'Cannot delete category with subcategories'], 422);
        }

        $category->delete();
        Cache::forget("categories_" . tenant('id'));
        Cache::forget("category_{$category->id}_" . tenant('id'));

        return response()->json(null, 204);
    }

    public function bulkDelete(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:categories,id'
        ]);

        // Check for categories with subcategories
        $categoriesWithSubcategories = Category::whereIn('id', $request->ids)
            ->whereHas('subcategories')
            ->pluck('id');

        if ($categoriesWithSubcategories->isNotEmpty()) {
            return response()->json([
                'message' => 'Some categories have subcategories and cannot be deleted',
                'categories_with_subcategories' => $categoriesWithSubcategories
            ], 422);
        }

        Category::whereIn('id', $request->ids)->delete();
        Cache::forget("categories_" . tenant('id'));

        return response()->json(['message' => 'Categories deleted successfully']);
    }

    public function exportExcell()
    {
        $categories = Category::with('parentCategory')->get();

        if ($categories->isEmpty()) {
            return response()->json(['message' => 'No categories to export'], 404);
        }

        $fileName = 'categories_' . date('Y-m-d_H-i-s') . '.xlsx';
        return Excel::download(new Export($categories), $fileName);
    }

    public function exportPdf()
    {
        $categories = Category::with('parentCategory')->get();

        if ($categories->isEmpty()) {
            return response()->json(['message' => 'No categories to export'], 404);
        }

        $fileName = 'categories_' . date('Y-m-d_H-i-s') . '.pdf';
        return Excel::download(new ExportPDF($categories), $fileName);
    }

    public function importFromExcel(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:xlsx,xls',
        ]);

        try {
            $import = new DynamicExcelImport(Category::class);
            Excel::import($import, $request->file('file'));

            Cache::forget("categories_" . tenant('id'));

            return response()->json(['message' => 'Categories imported successfully']);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error importing categories: ' . $e->getMessage()], 500);
        }
    }
}
