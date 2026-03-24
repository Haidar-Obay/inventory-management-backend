<?php

declare(strict_types=1);

namespace App\Actions\Customer;

use App\Http\Resources\Customer\CustomerForInvoiceResource;
use App\Models\Customer;

class GetCustomerForInvoiceAction
{
    /**
     * @return array<string, mixed>
     */
    public function execute(Customer $customer): array
    {
        $customer->load([
            'salesman:id,name',
            'billingAddresses:id,address_line1,address_line2,city_id,country_id,building,floor,zip_code',
            'shippingAddresses:id,address_line1,address_line2,city_id,country_id,building,floor,zip_code',
            'openingBalances' => function ($query) {
                $query->where('is_active', true)
                    ->with([
                        'currency:id,code,name,iso_code',
                        'paymentTerm:id,code,name,nb_days',
                        'paymentMethod:id,code,name',
                    ]);
            },
        ]);

        return (new CustomerForInvoiceResource($customer))->toArray(request());
    }
}
