<?php

declare(strict_types=1);

namespace App\Actions\Customer;

use App\Models\Customer;

class GetCustomerGridAction
{
    public function execute(): array
    {
        // Optimized query - only fetch essential data for grid
        $customers = Customer::select([
            'id',
            'first_name',
            'last_name',
            'phone1',
            'email',
            'active',
            'black_listed',
            'created_at',
            'customer_group_id',
            'salesman_id',
        ])->with([
            'customerGroup:id,name',
            'salesman:id,name',
            'openingBalances.paymentTerm:id,name',
            'openingBalances.paymentMethod:id,name',
        ]);

        // Get the customers data
        $customersData = $customers->get();

        // Transform the response to only include essential fields for grid
        return $customersData->map(function ($customer) {
            return [
                // Core Identity (Essential)
                'id' => $customer->id,
                'first_name' => $customer->first_name,
                'last_name' => $customer->last_name,
                'phone1' => $customer->phone1,
                'email' => $customer->email,
                'active' => $customer->active,

                // Business Context (Important)
                'customer_group' => $customer->customerGroup ? [
                    'id' => $customer->customerGroup->id,
                    'name' => $customer->customerGroup->name,
                ] : null,
                'salesman' => $customer->salesman ? [
                    'id' => $customer->salesman->id,
                    'name' => $customer->salesman->name,
                ] : null,
                'payment_term' => $customer->openingBalances->first()?->paymentTerm ? [
                    'id' => $customer->openingBalances->first()->paymentTerm->id,
                    'name' => $customer->openingBalances->first()->paymentTerm->name,
                ] : null,
                'payment_method' => $customer->openingBalances->first()?->paymentMethod ? [
                    'id' => $customer->openingBalances->first()->paymentMethod->id,
                    'name' => $customer->openingBalances->first()->paymentMethod->name,
                ] : null,

                // Status Indicators (Useful)
                'black_listed' => $customer->black_listed,
                'created_at' => $customer->created_at,
            ];
        })->all();
    }
}

