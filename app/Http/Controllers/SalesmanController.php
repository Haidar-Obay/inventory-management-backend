<?php

namespace App\Http\Controllers;

use App\Models\Salesman;
use Illuminate\Http\Request;
use App\Http\Requests\Salesman\StoreSalesmanRequest;
use App\Http\Requests\Salesman\UpdateSalesmanRequest;
use Illuminate\Support\Facades\Auth;

class SalesmanController extends Controller
{
    public function index()
    {
        $this->authorizeAction();

        $salesmen = Salesman::withCount('customers');

        return response()->json([
            'status' => true,
            'message' => 'Salesmen fetched successfully.',
            'data' => $salesmen,
        ]);
    }

    public function show(Salesman $salesman)
    {
        $this->authorizeAction();

        $salesman->loadCount('customers');

        return response()->json([
            'status' => true,
            'message' => 'Salesman details fetched successfully.',
            'data' => $salesman,
        ]);
    }

    public function store(StoreSalesmanRequest $request)
    {
        $this->authorizeAction();

        $salesman = Salesman::create($request->validated());

        return response()->json([
            'status' => true,
            'message' => 'Salesman created successfully.',
            'data' => $salesman,
        ], 201);
    }

    public function update(UpdateSalesmanRequest $request, Salesman $salesman)
    {
        $this->authorizeAction();

        $salesman->update($request->validated());

        return response()->json([
            'status' => true,
            'message' => 'Salesman updated successfully.',
            'data' => $salesman,
        ]);
    }

    public function destroy(Salesman $salesman)
    {
        $this->authorizeAction();

        $salesman->delete();

        return response()->json([
            'status' => true,
            'message' => 'Salesman deleted successfully.',
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
