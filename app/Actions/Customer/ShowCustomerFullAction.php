<?php

declare(strict_types=1);

namespace App\Actions\Customer;

use App\Http\Resources\Customer\CustomerFullResource;
use App\Models\Customer;

class ShowCustomerFullAction
{
    /**
     * @return array<string, mixed>
     */
    public function execute(Customer $customer): array
    {
        $customer->load([
            'customerGroup:id,name',
            'salesman:id,name',
            'collector:id,name',
            'supervisor:id,name',
            'manager:id,name',
            'trade:id,name',
            'companyCode:id,code',
            'businessType:id,name',
            'salesChannel:id,name',
            'distributionChannel:id,name',
            'mediaChannel:id,name',
            'mediaType:id,name',
            'referral:id,name',
            'associations:id,name',
            'addresses:id,address_line1,address_line2,country_id,city_id,district_id,zone_id,building,block,floor,side,appartment,zip_code',
            'billingAddresses:id,address_line1,address_line2,country_id,city_id,district_id,zone_id,building,block,floor,side,appartment,zip_code',
            'shippingAddresses:id,address_line1,address_line2,country_id,city_id,district_id,zone_id,building,block,floor,side,appartment,zip_code',
            'primaryBillingAddress:id,address_line1,address_line2,country_id,city_id,district_id,zone_id,building,block,floor,side,appartment,zip_code',
            'primaryShippingAddress:id,address_line1,address_line2,country_id,city_id,district_id,zone_id,building,block,floor,side,appartment,zip_code',
            'primaryContact:id,name,title,work_phone,mobile,position,extension,is_primary',
            'contacts:id,name,title,work_phone,mobile,position,extension,is_primary',
            'attachments:id,customer_id,file_name,file_path,file_type,file_size,description,category,is_public,created_at,updated_at',
            'creditLimits:id,currency_id,credit_limit,notes,is_active',
            'chequeLimits:id,currency_id,max_cheques,notes,is_active',
            'openingBalances:id,currency_id,opening_amount,opening_date,notes,payment_term_id,payment_method_id,allow_credit,payment_day,track_payment,settlement_method,accept_cheques,is_active',
        ]);

        $customer->load('contacts');

        $creditLimits = $customer->activeCreditLimits()->with('currency:id,code,name,iso_code')->get();
        $chequeLimits = $customer->activeChequeLimits()->with('currency:id,code,name,iso_code')->get();
        $openingBalances = $customer->activeOpeningBalances()
            ->with([
                'currency:id,code,name,iso_code',
                'paymentTerm:id,code,name,nb_days',
                'paymentMethod:id,code,name',
            ])->get();

        $payload = [
            'customer' => $customer,
            'creditLimits' => $creditLimits,
            'chequeLimits' => $chequeLimits,
            'openingBalances' => $openingBalances,
        ];

        return (new CustomerFullResource($payload))->toArray(request());
    }
}
