<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\City;
use Illuminate\Http\Request;
use App\Http\Requests\StoreCityRequest;
class CityController extends Controller
{
    public function index()
    {
        $cities = City::withCount('addresses')->paginate(10);

        return response()->json([
            'status' => true,
            'message' => 'Cities fetched successfully.',
            'data' => $cities,
        ]);
    }

    public function store(StoreCityRequest $request)
{
    $city = City::create($request->validated());

    return response()->json([
        'message' => 'City created successfully',
        'city' => $city,
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
            'name' => 'required|string|max:255',
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
}

