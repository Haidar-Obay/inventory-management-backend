<?php

namespace App\Http\Controllers;

use App\Http\Requests\Currency\StoreCurrencyRequest;
use App\Http\Requests\Currency\UpdateCurrencyRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Request;
use App\Models\Currency;

class CurrencyController extends Controller
{
    public function index()
    {
        return response()->json(Currency::all());
    }

    public function store(StoreCurrencyRequest $request)
    {
        $apiKey = config('services.exchange_rate.key');
        $baseCurrency = 'USD';
        $url = "https://v6.exchangerate-api.com/v6/{$apiKey}/latest/{$baseCurrency}";

        $response = Http::get($url);
        if (!$response->ok()) {
            return response()->json(['message' => 'Failed to fetch exchange rate.'], 500);
        }

        $rate = $response['conversion_rates'][$request->code] ?? null;
        if (!$rate) {
            return response()->json(['message' => 'Invalid currency code.'], 422);
        }

        $currency = Currency::create([
            'name' => $request->name,
            'code' => $request->code,
            'iso_code' => $request->iso_code,
            'rate' => $rate,
        ]);

        return response()->json($currency, 201);
    }

    public function show($id)
    {
        $currency = Currency::findOrFail($id);

        $apiKey = config('services.exchange_rate.key');
        $baseCurrency = 'USD';
        $url = "https://v6.exchangerate-api.com/v6/{$apiKey}/latest/{$baseCurrency}";

        $response = Http::get($url);
        if ($response->ok()) {
            $currency->rate = $response['conversion_rates'][$currency->code] ?? $currency->rate;
        }

        return response()->json($currency);
    }

    public function update(UpdateCurrencyRequest $request, $id)
    {
        $currency = Currency::findOrFail($id);

        $apiKey = config('services.exchange_rate.key');
        $baseCurrency = 'USD';
        $url = "https://v6.exchangerate-api.com/v6/{$apiKey}/latest/{$baseCurrency}";

        $response = Http::get($url);
        if (!$response->ok()) {
            return response()->json(['message' => 'Failed to fetch exchange rate.'], 500);
        }

        $rate = $response['conversion_rates'][$request->code] ?? null;
        if (!$rate) {
            return response()->json(['message' => 'Invalid currency code.'], 422);
        }

        $currency->update([
            'name' => $request->name,
            'code' => $request->code,
            'iso_code' => $request->iso_code,
            'rate' => $rate,
        ]);

        return response()->json($currency);
    }
}
