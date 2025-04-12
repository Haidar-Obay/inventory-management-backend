<?php

namespace App\Http\Controllers;

use App\Models\Country;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;

class CountryController extends Controller
{
    public function index()
    {
        $this->authorizeAction();

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
        $this->authorizeAction();

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
        $this->authorizeAction();

        $country->loadCount('addresses');

        return response()->json([
            'status' => true,
            'message' => 'Country details fetched successfully.',
            'data' => $country,
        ]);
    }

    public function update(Request $request, Country $country)
    {
        $this->authorizeAction();

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
        $this->authorizeAction();

        $country->delete();

        return response()->json([
            'status' => true,
            'message' => 'Country deleted successfully.',
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
