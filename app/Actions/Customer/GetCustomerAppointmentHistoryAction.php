<?php

declare(strict_types=1);

namespace App\Actions\Customer;

use App\Models\Asset;
use App\Models\Customer;
use App\Models\Specialist;
use Illuminate\Http\JsonResponse;

class GetCustomerAppointmentHistoryAction
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

        $appointments = $customer->appointments()
            ->with([
                'services:id,name',
                'visit:id,appointment_id,status,arrived_at,in_progress_at,completed_at,cancelled_at',
            ])
            ->orderBy('start_at', 'desc')
            ->get();

        $appointments->each(function ($appointment) {
            $specialistIds = $appointment->services->pluck('pivot.specialist_id')->filter()->unique()->toArray();
            $assetIds = $appointment->services->pluck('pivot.asset_id')->filter()->unique()->toArray();

            $specialists = $specialistIds ? Specialist::whereIn('id', $specialistIds)->get()->keyBy('id') : collect();
            $assets = $assetIds ? Asset::whereIn('id', $assetIds)->get()->keyBy('id') : collect();

            $appointment->services->each(function ($service) use ($specialists, $assets) {
                $specialistId = $service->pivot->specialist_id ?? null;
                $assetId = $service->pivot->asset_id ?? null;

                if ($specialistId && $specialists->has($specialistId)) {
                    $service->setRelation('specialist', $specialists->get($specialistId));
                }

                if ($assetId && $assets->has($assetId)) {
                    $service->setRelation('asset', $assets->get($assetId));
                }
            });
        });

        return response()->json([
            'status' => true,
            'message' => 'Appointment history fetched successfully.',
            'data' => $appointments,
        ]);
    }
}
