<?php

namespace App\Http\Controllers;

use App\Models\Country;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\Export;

class CountryController extends Controller
{
    public function index()
    {
        $countries = Country::withCount('addresses')
            ->orderBy('name')
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'Countries fetched successfully.',
            'data' => $countries,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:countries,name',
        ]);

        $country = Country::create($validated);

        return response()->json([
            'status' => true,
            'message' => 'Country created successfully.',
            'data' => $country,
        ], 201);
    }

    public function show(Country $country)
    {
      $country->loadCount('addresses');

        return response()->json([
            'status' => true,
            'message' => 'Country details fetched successfully.',
            'data' => $country,
        ]);
    }

    public function update(Request $request, Country $country)
    {
        $validated = $request->validate([
            'name' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('countries', 'name')->ignore($country->id),
            ],
        ]);

        $country->update($validated);

        return response()->json([
            'status' => true,
            'message' => 'Country updated successfully.',
            'data' => $country,
        ]);
    }

    public function destroy(Country $country)
    {
        $country->delete();

        return response()->json([
            'status' => true,
            'message' => 'Country deleted successfully.',
        ]);
    }
    public function export()
    {
        $countries = Country::query();
        $collection =  $countries->get();
        if ($collection->isEmpty()) {
            return response()->json(['message' => 'No currencies found.'], 404);
        }
        $columns = ['id', 'name'];
        $headings = ['ID', 'Name'];
        return Excel::download(new Export($countries, $columns, $headings), 'countries.xlsx');
    }
}
