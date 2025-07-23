<?php

namespace App\Http\Controllers;

use App\Models\TableTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class TableTemplateController extends Controller
{
    /**
     * Get all templates for a specific table
     */
    public function index(string $tableName): JsonResponse
    {
        $templates = TableTemplate::where('table_name', $tableName)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($templates);
    }

    /**
     * Get a specific template
     */
    public function show(string $tableName, string $templateId): JsonResponse
    {
        $template = TableTemplate::where('table_name', $tableName)
            ->where('id', $templateId)
            ->first();

        if (!$template) {
            return response()->json(['message' => 'Template not found'], 404);
        }

        return response()->json($template);
    }

    /**
     * Create a new template
     */
    public function store(Request $request, string $tableName): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'visible_columns' => 'required|array',
            'visible_columns.*' => 'boolean',
            'column_widths' => 'required|array',
            'column_widths.*' => 'string|nullable',
            'column_order' => 'required|array',
            'column_order.*' => 'string',
            'headerColor' => 'nullable|string|max:255',
            'showHeaderSeparator' => 'boolean',
            'showHeaderColSeparator' => 'boolean',
            'showBodyColSeparator' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 400);
        }

        // Validate table name exists in the system
        $validTables = [
            'customers',
            'customerGroups',
            'salesmen',
            'items',
            'categories',
            'brands',
            'productLines',
            'projects',
            'costCenters',
            'departments',
            'trades',
            'companyCodes',
            'jobs',
            'countries',
            'cities',
            'districts',
            'zones',
            'paymentMethods',
            'paymentTerms',
        ]; // Add your valid tables heree 
        if (!in_array($tableName, $validTables)) {
            return response()->json(['message' => 'Invalid table name'], 400);
        }

        // Check for unique template name within table
        $existingTemplate = TableTemplate::where('table_name', $tableName)
            ->where('name', $request->input('name'))
            ->first();

        if ($existingTemplate) {
            return response()->json([
                'message' => 'A template with this name already exists for this table.',
                'errors' => ['name' => ['A template with this name already exists for this table.']]
            ], 409);
        }

        $template = TableTemplate::create([
            'name' => $request->input('name'),
            'table_name' => $tableName,
            'visible_columns' => $request->input('visible_columns'),
            'column_widths' => $request->input('column_widths'),
            'column_order' => $request->input('column_order'),
            'headerColor' => $request->input('headerColor'),
            'showHeaderSeparator' => $request->boolean('showHeaderSeparator', false),
            'showHeaderColSeparator' => $request->boolean('showHeaderColSeparator', false),
            'showBodyColSeparator' => $request->boolean('showBodyColSeparator', false),
        ]);

        return response()->json($template, 201);
    }

    /**
     * Update a template
     */
    public function update(Request $request, string $tableName, string $templateId): JsonResponse
    {
        $template = TableTemplate::where('table_name', $tableName)
            ->where('id', $templateId)
            ->first();

        if (!$template) {
            return response()->json(['message' => 'Template not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'visible_columns' => 'required|array',
            'visible_columns.*' => 'boolean',
            'column_widths' => 'required|array',
            'column_widths.*' => 'string|nullable',
            'column_order' => 'required|array',
            'column_order.*' => 'string',
            'headerColor' => 'nullable|string|max:255',
            'showHeaderSeparator' => 'boolean',
            'showHeaderColSeparator' => 'boolean',
            'showBodyColSeparator' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 400);
        }

        // Check for unique template name within table (excluding current template)
        $existingTemplate = TableTemplate::where('table_name', $tableName)
            ->where('name', $request->input('name'))
            ->where('id', '!=', $templateId)
            ->first();

        if ($existingTemplate) {
            return response()->json([
                'message' => 'A template with this name already exists for this table.',
                'errors' => ['name' => ['A template with this name already exists for this table.']]
            ], 409);
        }

        $template->update([
            'name' => $request->input('name'),
            'visible_columns' => $request->input('visible_columns'),
            'column_widths' => $request->input('column_widths'),
            'column_order' => $request->input('column_order'),
            'headerColor' => $request->input('headerColor'),
            'showHeaderSeparator' => $request->boolean('showHeaderSeparator', false),
            'showHeaderColSeparator' => $request->boolean('showHeaderColSeparator', false),
            'showBodyColSeparator' => $request->boolean('showBodyColSeparator', false),
        ]);

        return response()->json($template);
    }

    /**
     * Delete a template
     */
    public function destroy(string $tableName, string $templateId): JsonResponse
    {
        $template = TableTemplate::where('table_name', $tableName)
            ->where('id', $templateId)
            ->first();

        if (!$template) {
            return response()->json(['message' => 'Template not found'], 404);
        }

        $template->delete();

        return response()->json(['message' => 'Template deleted successfully']);
    }
}
