<?php

namespace App\Http\Controllers;

use App\Services\PricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PricingController extends Controller
{
    public function __construct(private PricingService $pricingService)
    {
    }

    public function resolvePrice(Request $request): JsonResponse
    {
        $request->validate([
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'association_id' => ['nullable', 'integer', 'exists:associations,id'],
            'referrer_id' => ['nullable', 'integer', 'exists:referrers,id'],
            'category_name' => ['nullable', 'string', 'max:255'],
            'specialist_id' => ['nullable', 'integer', 'exists:specialists,id'],
            'service_type' => ['nullable', 'string', 'in:on_site,on_call'],
            'hours' => ['nullable', 'integer', 'min:1'],
            'is_event' => ['nullable', 'boolean'],
        ]);

        $context = $request->only([
            'service_id', 'association_id', 'referrer_id', 'category_name', 'specialist_id', 'service_type', 'hours', 'is_event'
        ]);

        $result = $this->pricingService->resolvePrice($context);

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }
}
