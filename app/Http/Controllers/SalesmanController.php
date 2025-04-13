<?php

namespace App\Http\Controllers;
use App\Models\Salesman;
use Illuminate\Http\Request;
use App\Http\Requests\Salesman\StoreSalesmanRequest;
use App\Http\Requests\Salesman\UpdateSalesmanRequest;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Imports\DynamicExcelImport;

class SalesmanController extends Controller
{
    public function index()
    {
        $salesmen = Salesman::withCount('customers')->get();

        return response()->json([
            'status' => true,
            'message' => 'Salesmen fetched successfully.',
            'data' => $salesmen,
        ]);
    }

    public function show(Salesman $salesman)
    {
        $salesman->loadCount('customers');

        return response()->json([
            'status' => true,
            'message' => 'Salesman details fetched successfully.',
            'data' => $salesman,
        ]);
    }

    public function store(StoreSalesmanRequest $request)
    {
        $salesman = Salesman::create($request->validated());

        return response()->json([
            'status' => true,
            'message' => 'Salesman created successfully.',
            'data' => $salesman,
        ], 201);
    }

    public function update(UpdateSalesmanRequest $request, Salesman $salesman)
    {
        $salesman->update($request->validated());

        return response()->json([
            'status' => true,
            'message' => 'Salesman updated successfully.',
            'data' => $salesman,
        ]);
    }

    public function destroy(Salesman $salesman)
    {
        $salesman->delete();

        return response()->json([
            'status' => true,
            'message' => 'Salesman deleted successfully.',
        ]);
    }
    public function exportExcell()
    {
        $salesmenQuery = Salesman::query();

        // Check if any data exists before exporting
        if (!$salesmenQuery->exists()) {
            return response()->json(['message' => 'No Salesman found.'], 404);
        }

        // Instead of transforming the Eloquent models, let's use selectRaw to convert bools to readable format in SQL
        $transformedQuery = Salesman::query()
            ->selectRaw('id, name, email, phone1, phone2, address, fix_commission,
            CASE WHEN is_inactive THEN \'Yes\' ELSE \'No\' END as is_inactive');

        $columns = [
            'id',
            'name',
            'email',
            'phone1',
            'phone2',
            'address',
            'fix_commission',
            'is_inactive',
        ];

        $headings = [
            'ID',
            'Name',
            'Email',
            'Phone 1',
            'Phone 2',
            'Address',
            'Fix Commission',
            'Is Inactive',
        ];

        return Excel::download(new Export($transformedQuery, $columns, $headings), 'Salesman.xlsx');
    }


    public function exportPdf(ExportPDF $pdfService)
    {
        $salesmen = Salesman::select(
            'id',
            'name',
            'email',
            'phone1',
            'phone2',
            'address',
            'fix_commission',
            'is_inactive',
        )->get();

        if ($salesmen->isEmpty()) {
            return response()->json(['message' => 'No salesmen found.'], 404);
        }

        $title = 'Salesmen Group Report';
        $headers = [
            'id' => 'ID',
            'name' => 'Name',
            'email' => 'Email',
            'phone1' => 'Phone 1',
            'phone2' => 'Phone 2',
            'address' => 'Address',
            'fix_commission' => 'Fix Commission',
            'is_inactive' => 'Is Inactive',
        ];
        $data = $salesmen->toArray();

        $pdf = $pdfService->generatePdf($title, $headers, $data);
        return $pdf->download('Salesmen.pdf');
    }

    public function importFromExcel(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv',
        ]);

        $import = new DynamicExcelImport(
            Salesman::class,
            [
                'name',
                'email',
                'phone1',
                'phone2',
                'address',
                'fix_commission',
                'is_inactive'
            ],
            function ($row) {
                $errors = [];

                if (empty($row['name'])) {
                    $errors[] = 'Missing name';
                } elseif (preg_match('/\d/', $row['name'])) {
                    $errors[] = 'Name cannot contain numbers';
                }

                if (empty($row['email'])) {
                    $errors[] = 'Missing email';
                } elseif (!filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
                    $errors[] = 'Invalid email format';
                }

                if (empty($row['phone1']) || !ctype_digit(strval($row['phone1']))) {
                    $errors[] = 'Invalid or missing phone1';
                }

                if (empty($row['phone2']) || !ctype_digit(strval($row['phone2']))) {
                    $errors[] = 'Invalid or missing phone2';
                }

                if (empty($row['address'])) {
                    $errors[] = 'Missing address';
                }

                if (!isset($row['fix_commission']) || !is_numeric($row['fix_commission'])) {
                    $errors[] = 'Missing or invalid fix_commission';
                }

                if (!isset($row['is_inactive'])) {
                    $errors[] = 'Missing is_inactive';
                }

                return $errors;
            },
            function ($row) {
                return [
                    'name' => $row['name'],
                    'email' => $row['email'],
                    'phone1' => $row['phone1'],
                    'phone2' => $row['phone2'],
                    'address' => $row['address'],
                    'fix_commission' => floatval($row['fix_commission']),
                    'is_inactive' => boolval($row['is_inactive']),
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
