<?php

namespace App\Http\Controllers;

use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Imports\DynamicExcelImport;
use App\Models\TaxGroup;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class TaxGroupController extends Controller
{
    public function index()
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_tax_groups";

        $taxGroups = app('cache')->store('database')->get($key);

        if (! $taxGroups) {
            $taxGroups = TaxGroup::all();
            app('cache')->store('database')->forever($key, $taxGroups);
        }

        return response()->json($taxGroups);
    }

    public function store(Request $request)
    {
        $validated = $request->validate(TaxGroup::$rules);

        // If setting as default, unset other defaults
        if (! empty($validated['default']) && $validated['default']) {
            TaxGroup::where('default', true)->update(['default' => false]);
        }

        $nextId = $this->computeNextAvailableId(TaxGroup::class, 'id');
        $taxGroup = new TaxGroup($validated);
        $taxGroup->id = $nextId;
        $taxGroup->save();
        app('cache')->store('database')->forget('tenant_'.tenant('id').'_tax_groups');

        return response()->json($taxGroup, 201);
    }

    public function show(TaxGroup $taxGroup)
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_tax_group_{$taxGroup->id}";

        $cachedTaxGroup = app('cache')->store('database')->get($key);

        if (! $cachedTaxGroup) {
            $cachedTaxGroup = $taxGroup;
            app('cache')->store('database')->forever($key, $cachedTaxGroup);
        }

        return response()->json($cachedTaxGroup);
    }

    public function update(Request $request, TaxGroup $taxGroup)
    {
        $rules = TaxGroup::$rules;
        $rules['code'] = 'required|string|max:50|unique:tax_groups,code,'.$taxGroup->id;

        $validated = $request->validate($rules);

        // If setting as default, unset other defaults
        if (! empty($validated['default']) && $validated['default']) {
            TaxGroup::where('default', true)->where('id', '!=', $taxGroup->id)->update(['default' => false]);
        }

        $taxGroup->update($validated);

        app('cache')->store('database')->forget('tenant_'.tenant('id').'_tax_groups');
        app('cache')->store('database')->forget('tenant_'.tenant('id')."_tax_group_{$taxGroup->id}");

        return response()->json($taxGroup);
    }

    public function destroy(TaxGroup $taxGroup)
    {
        $identifier = $taxGroup->name ?? $taxGroup->code ?? "ID: {$taxGroup->id}";

        $taxGroup->delete();
        app('cache')->store('database')->forget('tenant_'.tenant('id').'_tax_groups');
        app('cache')->store('database')->forget('tenant_'.tenant('id')."_tax_group_{$taxGroup->id}");

        return response()->json([
            'status' => true,
            'message' => 'Tax group deleted successfully.',
        ]);
    }

    public function bulkDelete(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:tax_groups,id',
        ]);

        $ids = $request->input('ids');
        $skipped = [];
        $deleted = 0;

        foreach ($ids as $id) {
            try {
                $taxGroup = TaxGroup::find($id);

                if (! $taxGroup) {
                    $skipped[] = [
                        'id' => $id,
                        'name' => "ID: {$id}",
                        'reason' => 'Tax group not found.',
                    ];

                    continue;
                }

                $identifier = $taxGroup->name ?? $taxGroup->code ?? "ID: {$id}";

                $taxGroup->delete();
                $deleted++;
                app('cache')->store('database')->forget('tenant_'.tenant('id')."_tax_group_{$id}");
            } catch (\Exception $e) {
                $taxGroup = TaxGroup::find($id);
                $identifier = $taxGroup?->name ?? $taxGroup?->code ?? "ID: {$id}";
                $skipped[] = [
                    'id' => $id,
                    'name' => $identifier,
                    'reason' => $e->getMessage(),
                ];
            }
        }

        app('cache')->store('database')->forget('tenant_'.tenant('id').'_tax_groups');

        return response()->json([
            'message' => 'Bulk delete completed.',
            'deleted_count' => $deleted,
            'skipped' => $skipped,
        ]);
    }

    public function exportExcell()
    {
        $taxGroups = TaxGroup::all();

        if ($taxGroups->isEmpty()) {
            return response()->json(['message' => 'No tax groups to export'], 404);
        }

        $fileName = 'tax_groups_'.date('Y-m-d_H-i-s').'.xlsx';

        return Excel::download(new Export($taxGroups, ['id', 'code', 'name', 'value', 'active', 'default', 'created_at', 'updated_at'], ['ID', 'Code', 'Name', 'Value', 'Active', 'Default', 'Created At', 'Updated At']), $fileName);
    }

    public function exportPdf()
    {
        $taxGroups = TaxGroup::all();

        if ($taxGroups->isEmpty()) {
            return response()->json(['message' => 'No tax groups to export'], 404);
        }

        $fileName = 'tax_groups_'.date('Y-m-d_H-i-s').'.pdf';

        return Excel::download(new ExportPDF($taxGroups, ['id', 'code', 'name', 'value', 'active', 'default', 'created_at', 'updated_at'], ['ID', 'Code', 'Name', 'Value', 'Active', 'Default', 'Created At', 'Updated At']), $fileName);
    }

    public function importFromExcel(Request $request)
    {
        $request->validate([
            'file' => [
                'required',
                'file',
                'mimes:xlsx,xls,csv,txt,text/plain,text/csv,application/csv',
            ],
            'type' => 'nullable|string|in:fresh,mapping',
            'mapping' => 'nullable|array',
        ], [
            'file.mimes' => 'The file field must be a file of type: xlsx, xls, csv',
        ]);

        // If type is 'fresh', delete all records first
        if ($request->input('type') === 'fresh') {
            TaxGroup::truncate();
        }

        // If type is 'mapping', use provided mapping, else use default
        $mapping = $request->input('mapping');
        $fields = $mapping ? array_values($mapping) : ['code', 'name', 'value', 'active', 'default'];

        try {
            $import = new DynamicExcelImport(
                TaxGroup::class,
                $fields,
                function ($row) use ($mapping) {
                    $errors = [];
                    $codeKey = $mapping ? array_search('code', $mapping) : 'code';
                    $nameKey = $mapping ? array_search('name', $mapping) : 'name';
                    $valueKey = $mapping ? array_search('value', $mapping) : 'value';

                    if (empty($row[$codeKey])) {
                        $errors[] = 'Missing code';
                    }
                    if (empty($row[$nameKey])) {
                        $errors[] = 'Missing name';
                    }
                    if (empty($row[$valueKey]) || ! is_numeric($row[$valueKey])) {
                        $errors[] = 'Missing or invalid value';
                    }

                    return $errors;
                },
                function ($row) use ($mapping) {
                    $codeKey = $mapping ? array_search('code', $mapping) : 'code';
                    $nameKey = $mapping ? array_search('name', $mapping) : 'name';
                    $valueKey = $mapping ? array_search('value', $mapping) : 'value';
                    $activeKey = $mapping ? array_search('active', $mapping) : 'active';
                    $defaultKey = $mapping ? array_search('default', $mapping) : 'default';

                    return [
                        'code' => $row[$codeKey] ?? null,
                        'name' => $row[$nameKey] ?? null,
                        'value' => $row[$valueKey] ?? null,
                        'active' => boolval($row[$activeKey] ?? true),
                        'default' => boolval($row[$defaultKey] ?? false),
                    ];
                },
                true // Enable header validation
            );

            Excel::import($import, $request->file('file'));

            // Check if headers were valid
            if (! $import->areHeadersValid()) {
                $headerResult = $import->getHeaderValidationResult();

                return response()->json([
                    'success' => false,
                    'message' => 'Invalid Excel file headers',
                    'header_validation' => $headerResult,
                    'errors' => [
                        'missing_headers' => $headerResult['missing'],
                        'extra_headers' => $headerResult['extra'],
                        'expected_headers' => $headerResult['expected_headers'],
                        'actual_headers' => $headerResult['excel_headers'],
                    ],
                ], 422);
            }

            app('cache')->store('database')->forget('tenant_'.tenant('id').'_tax_groups');

            return response()->json([
                'message' => 'Tax groups imported successfully',
                'rows_imported' => $import->getImportedCount(),
                'rows_skipped_count' => $import->getSkippedCount(),
                'skipped_rows' => $import->getSkippedRows(),
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error importing tax groups: '.$e->getMessage()], 500);
        }
    }
}
