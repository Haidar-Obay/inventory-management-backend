<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Country;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CountryController extends Controller
{
    public function index()
    {
        $countries = Country::withCount('addresses')->paginate(10);

        return response()->json([
            'status' => true,
            'message' => 'Countries fetched successfully.',
            'data' => $countries,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'unique:countries,name|required|string|max:255',
        ]);

        $country = Country::create($validated);

        return response()->json([
            'message' => 'Country created successfully',
            'country' => $country,
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
            'name' => 
            [
            'sometimes',
            'string',
            'max:255',
            Rule::unique('countries', 'name')->ignore($country->id)
            ]
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
}
