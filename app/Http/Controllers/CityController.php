<?php

namespace App\Http\Controllers;

use App\Models\City;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\Export;

class CityController extends Controller
{
    public function index()
    {
        $cities = City::withCount('addresses')
            ->orderBy('name')
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'Cities fetched successfully.',
            'data' => $cities,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:cities,name',
        ]);

        $city = City::create($validated);

        return response()->json([
            'status' => true,
            'message' => 'City created successfully.',
            'data' => $city,
        ], 201);
    }

    public function show(City $city)
    {
        $city->loadCount('addresses');

        return response()->json([
            'status' => true,
            'message' => 'City details fetched successfully.',
            'data' => $city,
        ]);
    }

    public function update(Request $request, City $city)
    {
        $validated = $request->validate([
            'name' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('cities', 'name')->ignore($city->id),
            ],
        ]);

        $city->update($validated);

        return response()->json([
            'status' => true,
            'message' => 'City updated successfully.',
            'data' => $city,
        ]);
    }

    public function destroy(City $city)
    {
        $city->delete();

        return response()->json([
            'status' => true,
            'message' => 'City deleted successfully.',
        ]);
    }
    public function export()
    {
        $cities = City::withCount('addresses')
            ->orderBy('name');
        $collection =  $cities->get();
        if ($collection->isEmpty()) {
            return response()->json(['message' => 'No currencies found.'], 404);
        }
        $columns = ['id', 'name'];
        $headings = ['ID', 'Name'];

        return Excel::download(new Export($cities, $columns, $headings), 'cities.xlsx');
    }
}
