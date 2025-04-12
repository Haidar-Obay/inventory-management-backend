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
        $this->authorizeAction();

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
        $this->authorizeAction();

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
        $this->authorizeAction();

        $customerGroup->loadCount('customers');

        return response()->json([
            'status' => true,
            'message' => 'Customer group details fetched successfully.',
            'data' => $customerGroup,
        ]);
    }

    public function update(Request $request, CustomerGroup $customerGroup)
    {
        $this->authorizeAction();

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
        $this->authorizeAction();

        $customerGroup->delete();

        return response()->json([
            'status' => true,
            'message' => 'Customer group deleted successfully.',
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
