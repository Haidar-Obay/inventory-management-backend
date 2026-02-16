<?php

namespace App\Http\Controllers;

use App\Http\Requests\CashAccount\StoreCashAccountRequest;
use App\Http\Requests\CashAccount\UpdateCashAccountRequest;
use App\Models\CashAccount;
use Illuminate\Http\Request;

class CashAccountController extends Controller
{
    public function index()
    {
        $cashAccounts = CashAccount::with('currency')->orderBy('id')->get();

        return response()->json([
            'status' => true,
            'message' => 'Cash accounts fetched successfully.',
            'data' => $cashAccounts,
        ]);
    }

    public function store(StoreCashAccountRequest $request)
    {
        $validated = $request->validated();
        $nextId = $this->computeNextAvailableId(CashAccount::class, 'id');
        $cashAccount = new CashAccount($validated);
        $cashAccount->id = $nextId;
        $cashAccount->save();
        $cashAccount->load('currency');

        return response()->json([
            'status' => true,
            'message' => 'Cash account created successfully.',
            'data' => $cashAccount,
        ], 201);
    }

    public function show(CashAccount $cash_account)
    {
        return response()->json([
            'status' => true,
            'message' => 'Cash account details fetched successfully.',
            'data' => $cash_account,
        ]);
    }

    public function update(UpdateCashAccountRequest $request, CashAccount $cash_account)
    {
        $validated = $request->validated();
        $cash_account->update($validated);
        $cash_account->load('currency');

        return response()->json([
            'status' => true,
            'message' => 'Cash account updated successfully.',
            'data' => $cash_account,
        ]);
    }

    public function destroy(CashAccount $cash_account)
    {
        $cash_account->delete();

        return response()->json([
            'status' => true,
            'message' => 'Cash account deleted successfully.',
        ]);
    }

    public function bulkDelete(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:cash_accounts,id',
        ]);

        CashAccount::whereIn('id', $request->ids)->delete();

        return response()->json([
            'message' => 'Bulk delete completed.',
            'deleted_count' => count($request->ids),
        ]);
    }
}
