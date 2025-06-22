<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Imports\DynamicExcelImport;

class CategoryController extends Controller
{
    public function index()
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_categories";

        $categories = app('cache')->store('database')->get($key);

        if (!$categories) {
            $categories = Category::with('parentCategory')->get();
            app('cache')->store('database')->forever($key, $categories);
        }

        return response()->json([
            'status' => true,
            'message' => 'Categories fetched successfully.',
            'data' => $categories,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'code' => 'required|string|unique:categories,code',
            'name' => 'required|string',
            'subcategory_of' => 'nullable|exists:categories,id',
            'active' => 'boolean',
        ]);

        // Check if the parent category is not itself a subcategory
        if ($request->subcategory_of) {
            $parentCategory = Category::find($request->subcategory_of);
            if ($parentCategory->subcategory_of) {
                return response()->json([
                    'status' => false,
                    'message' => 'Cannot create subcategory under another subcategory. Only top-level categories can have subcategories.',
                ], 422);
            }
        }

        // Prevent circular references
        if ($request->subcategory_of) {
            $parentCategory = Category::find($request->subcategory_of);
            if ($parentCategory->subcategory_of === $request->subcategory_of) {
                return response()->json(['message' => 'Circular reference detected in category hierarchy'], 422);
            }
        }

        $category = Category::create($request->all());
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_categories";

        app('cache')->store('database')->forget($key);

        return response()->json([
            'status' => true,
            'message' => 'Category created successfully.',
            'data' => $category,
        ]);
    }

    public function show(Category $category)
    {
        $category = Category::with('parentCategory', 'subcategories')->find($category->id);

        return response()->json([
            'status' => true,
            'message' => 'Category fetched successfully.',
            'data' => $category,
        ]);
    }

    public function update(Request $request, Category $category)
    {
        $request->validate([
            'code' => 'required|string|unique:categories,code,' . $category->id,
            'name' => 'required|string',
            'subcategory_of' => 'nullable|exists:categories,id',
            'active' => 'boolean',
        ]);

        // Check if the parent category is not itself a subcategory
        if ($request->subcategory_of) {
            $parentCategory = Category::find($request->subcategory_of);
            if ($parentCategory->subcategory_of) {
                return response()->json([
                    'status' => false,
                    'message' => 'Cannot assign subcategory under another subcategory. Only top-level categories can have subcategories.',
                ], 422);
            }
        }

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
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_categories";

        app('cache')->store('database')->forget($key);

        return response()->json([
            'status' => true,
            'message' => 'Category updated successfully.',
            'data' => $category,
        ]);
    }

    public function destroy(Category $category)
    {
        // Check if category has subcategories
        if ($category->subcategories()->exists()) {
            return response()->json(['message' => 'Cannot delete category with subcategories'], 422);
        }

        $category->delete();
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_categories";

        app('cache')->store('database')->forget($key);

        return response()->json([
            'status' => true,
            'message' => 'Category deleted successfully.',
        ]);
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
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_categories";

        app('cache')->store('database')->forget($key);

        return response()->json([
            'status' => true,
            'message' => 'Categories deleted successfully.',
        ]);
    }

    public function exportExcell()
    {
        $categories = Category::with('parentCategory')->orderBy('name');
        $collection = $categories->get();

        if ($collection->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'No categories to export',
            ], 404);
        }

        $columns = ['id', 'code', 'name', 'subcategory_of', 'active'];
        $headings = ['ID', 'Code', 'Name', 'Subcategory Of', 'Active'];

        $fileName = 'categories_' . date('Y-m-d_H-i-s') . '.xlsx';
        return Excel::download(new Export($categories, $columns, $headings), $fileName);
    }

    public function exportPdf()
    {
        $categories = Category::with('parentCategory')
            ->select('id', 'code', 'name', 'subcategory_of', 'active')
            ->get();

        if ($categories->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'No categories to export',
            ], 404);
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

            $tenantId = tenant('id');
            $key = "tenant_{$tenantId}_categories";

            app('cache')->store('database')->forget($key);

            return response()->json([
                'status' => true,
                'message' => 'Categories imported successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Error importing categories: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function getNames()
    {
        // Only get top-level categories (not subcategories)
        $categories = Category::whereNull('subcategory_of')
                ->select('id', 'name')
                ->orderBy('name')
                ->get()
                ->map(function ($category) {
                    return [
                        'id' => $category->id,
                        'name' => $category->name
                    ];
                });

        return response()->json([
            'status' => true,
            'message' => 'Category names fetched successfully.',
            'data' => $categories,
        ]);
    }
}
