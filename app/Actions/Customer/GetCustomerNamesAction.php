<?php

declare(strict_types=1);

namespace App\Actions\Customer;

use App\Http\Resources\Customer\CustomerNameResource;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;

class GetCustomerNamesAction
{
    public function execute(): JsonResponse
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_customer_names";

        $customers = app('cache')->store('database')->get($key);

        $needsRegeneration = false;
        if ($customers && $customers->isNotEmpty()) {
            $firstCustomer = $customers->first();
            if (! isset($firstCustomer['phone'])) {
                $needsRegeneration = true;
            }
        }

        if (! $customers || $needsRegeneration) {
            $customers = Customer::select('id', 'first_name', 'middle_name', 'last_name', 'phone1')
                ->orderBy('first_name')
                ->get()
                ->map(function ($customer) {
                    $parts = [
                        $customer->first_name,
                        $customer->middle_name,
                        $customer->last_name,
                    ];

                    return [
                        'id' => $customer->id,
                        'name' => trim(implode(' ', array_filter($parts))),
                        'phone' => $customer->phone1 ?? '',
                    ];
                });

            app('cache')->store('database')->forever($key, $customers);
        }

        return response()->json([
            'status' => true,
            'message' => 'Customer names fetched successfully.',
            'data' => CustomerNameResource::collection($customers)->resolve(),
        ]);
    }
}
