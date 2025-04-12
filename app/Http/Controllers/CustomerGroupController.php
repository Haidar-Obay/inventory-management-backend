<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;
use App\Models\CustomerGroup;

class CustomerGroupController extends Controller
{
    public function index()
    {
        $groups = CustomerGroup::withCount('customers')
            ->orderBy('name')
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'Customer groups fetched successfully.',
            'data' => $groups,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:customer_groups,name',
        ]);

        $group = CustomerGroup::create($validated);

        return response()->json([
            'status' => true,
            'message' => 'Customer group created successfully.',
            'data' => $group,
        ], 201);
    }

    public function show(CustomerGroup $customerGroup)
    {
        $customerGroup->loadCount('customers');

        return response()->json([
            'status' => true,
            'message' => 'Customer group details fetched successfully.',
            'data' => $customerGroup,
        ]);
    }

    public function update(Request $request, CustomerGroup $customerGroup)
    {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('customer_groups')->ignore($customerGroup->id),
            ],
        ]);

        $customerGroup->update($validated);

        return response()->json([
            'status' => true,
            'message' => 'Customer group updated successfully.',
            'data' => $customerGroup,
        ]);
    }

    public function destroy(CustomerGroup $customerGroup)
    {
        $customerGroup->delete();

        return response()->json([
            'status' => true,
            'message' => 'Customer group deleted successfully.',
        ]);
    }

}
