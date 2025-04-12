<?php

namespace App\Http\Controllers;
use App\Models\Salesman;
use App\Http\Requests\Salesman\StoreSalesmanRequest;
use App\Http\Requests\Salesman\UpdateSalesmanRequest;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\Export;


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
    public function export()
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

}
