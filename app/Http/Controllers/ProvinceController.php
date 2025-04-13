<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Province;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Imports\DynamicExcelImport;

class ProvinceController extends Controller
{
    public function index()
    {
        $provinces = Province::withCount('addresses')
            ->orderBy('name')
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'Provinces fetched successfully.',
            'data' => $provinces,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'unique:provinces,name|required|string|max:255',
        ]);

        $province = Province::create($validated);

        return response()->json([
            'message' => 'Province created successfully',
            'province' => $province,
        ], 201);
    }

    public function show(Province $province)
    {
        $province->loadCount('addresses');

        return response()->json([
            'status' => true,
            'message' => 'Province details fetched successfully.',
            'data' => $province,
        ]);
    }

    public function update(Request $request, Province $province)
    {
        $validated = $request->validate([
            'name' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('provinces', 'name')->ignore($province->id)
            ]
        ]);

        $province->update($validated);

        return response()->json([
            'status' => true,
            'message' => 'Province updated successfully.',
            'data' => $province,
        ]);
    }

    public function destroy(Province $province)
    {
        $province->delete();

        return response()->json([
            'status' => true,
            'message' => 'Province deleted successfully.',
        ]);
    }
    public function exportExcell()
    {
        $Province = Province::query();
        $collection =  $Province->get();
        if ($collection->isEmpty()) {
            return response()->json(['message' => 'No Province found.'], 404);
        }
        $columns = ['id', 'name'];
        $headings = ['ID', 'Name'];
        return Excel::download(new Export($Province, $columns, $headings), 'provinces.xlsx');
    }

    public function exportPdf(ExportPDF $pdfService)
    {
        $provinces = Province::select(
            'id',
            'name'
        )->get();

        if ($provinces->isEmpty()) {
            return response()->json(['message' => 'No provinces found.'], 404);
        }

        $title = 'Province Report';
        $headers = [
            'id' => 'Province ID',
            'name' => 'Province Name'
        ];
        $data = $provinces->toArray();

        $pdf = $pdfService->generatePdf($title, $headers, $data);
        return $pdf->download('Provinces.pdf');
    }

    public function importFromExcel(Request $request)
{
    $request->validate([
        'file' => 'required|file|mimes:xlsx,xls,csv',
    ]);

    $import = new DynamicExcelImport(
        Province::class,
        ['name'],
        function ($row) {
            $errors = [];

            if (empty($row['name'])) {
                $errors[] = 'Missing name';
            } elseif (preg_match('/[0-9]/', $row['name'])) {
                $errors[] = 'Province name must not contain numbers';
            }

            return $errors;
        },
        function ($row) {
            return [
                'name' => $row['name'],
            ];
        }
    );

    Excel::import($import, $request->file('file'));

    return response()->json([
        'success' => true,
        'rows_imported' => $import->getImportedCount(),
        'rows_skipped_count' => $import->getSkippedCount(),
        'skipped_rows' => $import->getSkippedRows(),
    ]);
}

}
