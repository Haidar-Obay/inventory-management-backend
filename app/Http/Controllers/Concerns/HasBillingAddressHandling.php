<?php

namespace App\Http\Controllers\Concerns;

trait HasBillingAddressHandling
{
    protected function hasAnyBillingField($request): bool
    {
        $billingFields = [
            'billing_address_line1',
            'billing_address_line2',
            'billing_country_id',
            'billing_city_id',
            'billing_district_id',
            'billing_zone_id',
            'billing_building',
            'billing_block',
            'billing_floor',
            'billing_side',
            'billing_apartment',
            'billing_zip_code',
            'billing_notes',
        ];

        foreach ($billingFields as $key) {
            if ($request->filled($key)) {
                return true;
            }
        }

        return false;
    }
}
