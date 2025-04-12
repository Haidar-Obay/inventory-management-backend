<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Province;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class ProvinceController extends Controller
{
    public function index()
    {
        $this->authorizeAction();

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
        $this->authorizeAction();

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
        $this->authorizeAction();

        $province->loadCount('addresses');

        return response()->json([
            'status' => true,
            'message' => 'Province details fetched successfully.',
            'data' => $province,
        ]);
    }

    public function update(Request $request, Province $province)
    {
        $this->authorizeAction();

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
        $this->authorizeAction();

        $province->delete();

        return response()->json([
            'status' => true,
            'message' => 'Province deleted successfully.',
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
