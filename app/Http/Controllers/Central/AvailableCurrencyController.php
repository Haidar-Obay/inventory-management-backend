<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\AvailableCurrency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

class AvailableCurrencyController extends Controller
{
    /**
     * Get all available currencies.
     */
    public function index(): JsonResponse
    {
        $currencies = AvailableCurrency::orderBy('name')->get();

        return response()->json([
            'status' => true,
            'data' => $currencies,
        ]);
    }

    /**
     * Get a specific currency.
     */
    public function show($id): JsonResponse
    {
        $currency = AvailableCurrency::findOrFail($id);

        return response()->json([
            'status' => true,
            'data' => $currency,
        ]);
    }

    /**
     * Create a new available currency.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required|string|size:3|unique:available_currencies,code|uppercase',
            'name' => 'required|string|max:255',
            'iso_code' => 'required|string|size:3|unique:available_currencies,iso_code|uppercase',
            'symbol' => 'nullable|string|max:10',
            'is_active' => 'boolean',
        ], [
            'code.required' => 'Currency code is required.',
            'code.size' => 'Currency code must be exactly 3 characters.',
            'code.unique' => 'This currency code already exists.',
            'code.uppercase' => 'Currency code must be uppercase.',
            'name.required' => 'Currency name is required.',
            'iso_code.required' => 'ISO code is required.',
            'iso_code.size' => 'ISO code must be exactly 3 characters.',
            'iso_code.unique' => 'This ISO code already exists.',
            'iso_code.uppercase' => 'ISO code must be uppercase.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $currency = AvailableCurrency::create([
            'code' => strtoupper($request->code),
            'name' => $request->name,
            'iso_code' => strtoupper($request->iso_code),
            'symbol' => $request->symbol,
            'is_active' => $request->input('is_active', true),
        ]);

        // Clear any caches that might be using available currencies
        Cache::forget('available_currencies_all');
        Cache::forget('available_currencies_active');

        return response()->json([
            'status' => true,
            'message' => 'Currency created successfully.',
            'data' => $currency,
        ], 201);
    }

    /**
     * Update an existing currency.
     */
    public function update(Request $request, $id): JsonResponse
    {
        $currency = AvailableCurrency::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'code' => 'sometimes|required|string|size:3|unique:available_currencies,code,'.$id.'|uppercase',
            'name' => 'sometimes|required|string|max:255',
            'iso_code' => 'sometimes|required|string|size:3|unique:available_currencies,iso_code,'.$id.'|uppercase',
            'symbol' => 'nullable|string|max:10',
            'is_active' => 'boolean',
        ], [
            'code.size' => 'Currency code must be exactly 3 characters.',
            'code.unique' => 'This currency code already exists.',
            'code.uppercase' => 'Currency code must be uppercase.',
            'iso_code.size' => 'ISO code must be exactly 3 characters.',
            'iso_code.unique' => 'This ISO code already exists.',
            'iso_code.uppercase' => 'ISO code must be uppercase.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $updateData = [];
        if ($request->has('code')) {
            $updateData['code'] = strtoupper($request->code);
        }
        if ($request->has('name')) {
            $updateData['name'] = $request->name;
        }
        if ($request->has('iso_code')) {
            $updateData['iso_code'] = strtoupper($request->iso_code);
        }
        if ($request->has('symbol')) {
            $updateData['symbol'] = $request->symbol;
        }
        if ($request->has('is_active')) {
            $updateData['is_active'] = $request->is_active;
        }

        $currency->update($updateData);

        // Clear caches
        Cache::forget('available_currencies_all');
        Cache::forget('available_currencies_active');

        return response()->json([
            'status' => true,
            'message' => 'Currency updated successfully.',
            'data' => $currency->fresh(),
        ]);
    }

    /**
     * Delete a currency.
     */
    public function destroy($id): JsonResponse
    {
        $currency = AvailableCurrency::findOrFail($id);

        // Check if currency is used by any tenants
        // We can't directly check tenant databases, but we can warn
        // In production, you might want to add a check here

        $currency->delete();

        // Clear caches
        Cache::forget('available_currencies_all');
        Cache::forget('available_currencies_active');

        return response()->json([
            'status' => true,
            'message' => 'Currency deleted successfully.',
        ]);
    }

    /**
     * Toggle active status of a currency.
     */
    public function toggleActive($id): JsonResponse
    {
        $currency = AvailableCurrency::findOrFail($id);
        $currency->update(['is_active' => ! $currency->is_active]);

        // Clear caches
        Cache::forget('available_currencies_all');
        Cache::forget('available_currencies_active');

        return response()->json([
            'status' => true,
            'message' => 'Currency status updated successfully.',
            'data' => $currency->fresh(),
        ]);
    }
}
