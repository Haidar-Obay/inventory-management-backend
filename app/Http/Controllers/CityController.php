<?php

namespace App\Http\Controllers;

use App\Models\City;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;

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
        $this->authorizeAction();

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
        $this->authorizeAction();

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
        $this->authorizeAction();

        $city->delete();

        return response()->json([
            'status' => true,
            'message' => 'City deleted successfully.',
        ]);
    }

    private function authorizeAction()
    {
        $user = Auth::user();

        if (!$user) {
            abort(response()->json(['message' => 'Unauthorized'], 401));
        }
    }
}
