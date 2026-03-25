<?php

declare(strict_types=1);

namespace App\Actions\Customer;

use App\Models\Customer;

class SearchCustomerByPhoneAction
{
    public function execute(string $phone): ?array
    {
        // Search in phone1, phone2, phone3 fields
        $customer = Customer::where('phone1', $phone)
            ->orWhere('phone2', $phone)
            ->orWhere('phone3', $phone)
            ->first();

        if (! $customer) {
            return null;
        }

        // Get primary billing address
        $primaryBillingAddress = $customer->primaryBillingAddress->first();
        $addressLine1 = $primaryBillingAddress ? $primaryBillingAddress->address_line1 : null;

        return [
            'id' => $customer->id,
            'first_name' => $customer->first_name,
            'middle_name' => $customer->middle_name,
            'last_name' => $customer->last_name,
            'date_of_birth' => $customer->date_of_birth,
            'place_of_birth' => $customer->place_of_birth,
            'gender' => $customer->gender,
            'file_number' => $customer->file_number,
            'phone1' => $customer->phone1,
            'phone2' => $customer->phone2,
            'phone3' => $customer->phone3,
            'address_line1' => $addressLine1,
            'black_listed' => $customer->black_listed,
        ];
    }
}
