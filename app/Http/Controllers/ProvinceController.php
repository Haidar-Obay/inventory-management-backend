<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Province;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\Export;

class ProvinceController extends Controller
{
    public function index()
    {
        $provinces = Province::withCount('addresses')
            ->orderBy('name');

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
    public function export()
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
}
