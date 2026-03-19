<?php

declare(strict_types=1);

namespace App\Actions\Customer;

use App\Models\Asset;
use App\Models\Customer;
use App\Models\Specialist;
use Illuminate\Http\JsonResponse;

class GetCustomerVisitHistoryAction
{
    public function execute(int $customerId): JsonResponse
    {
        $customer = Customer::find($customerId);

        if (! $customer) {
            return response()->json([
                'status' => false,
                'message' => 'Customer not found.',
                'data' => [],
            ], 404);
        }

        $visits = $customer->visits()
            ->with([
                'appointment.customers',
                'appointment.services',
                'services',
            ])
            ->orderBy('arrived_at', 'desc')
            ->get();

        foreach ($visits as $visit) {
            if ($visit->appointment) {
                $specialistIds = $visit->appointment->services->pluck('pivot.specialist_id')->filter()->unique()->toArray();
                $assetIds = $visit->appointment->services->pluck('pivot.asset_id')->filter()->unique()->toArray();

                $specialists = $specialistIds ? Specialist::whereIn('id', $specialistIds)->get()->keyBy('id') : collect();
                $assets = $assetIds ? Asset::whereIn('id', $assetIds)->get()->keyBy('id') : collect();

                $visit->appointment->services->each(function ($service) use ($specialists, $assets) {
                    $specialistId = $service->pivot->specialist_id ?? null;
                    $assetId = $service->pivot->asset_id ?? null;

                    if ($specialistId && $specialists->has($specialistId)) {
                        $service->setRelation('specialist', $specialists->get($specialistId));
                    }

                    if ($assetId && $assets->has($assetId)) {
                        $service->setRelation('asset', $assets->get($assetId));
                    }
                });
            }

            if ($visit->services->isNotEmpty()) {
                $specialistIds = $visit->services->pluck('pivot.specialist_id')->filter()->unique()->toArray();
                if (! empty($specialistIds)) {
                    $specialists = Specialist::whereIn('id', $specialistIds)->get()->keyBy('id');
                    $visit->services->each(function ($service) use ($specialists) {
                        $specialistId = $service->pivot->specialist_id ?? null;
                        if ($specialistId && $specialists->has($specialistId)) {
                            $service->setRelation('specialist', $specialists->get($specialistId));
                        }
                    });
                }
            }
        }

        return response()->json([
            'status' => true,
            'message' => 'Visit history fetched successfully.',
            'data' => $visits,
        ]);
    }
}
