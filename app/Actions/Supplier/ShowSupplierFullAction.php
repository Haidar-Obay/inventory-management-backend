<?php

declare(strict_types=1);

namespace App\Actions\Supplier;

use App\Http\Resources\Supplier\SupplierFullResource;
use App\Models\Supplier;

class ShowSupplierFullAction
{
    /**
     * @return array<string, mixed>
     */
    public function execute(Supplier $supplier): array
    {
        $supplier->load([
            'supplierGroup:id,name,code,active',
            'trade:id,name,code,active',
            // 'business_types' table does not have an 'active' column, so we only select existing columns
            'businessType:id,name,code',
            'currency:id,code,name,iso_code,symbol,active',
            'addresses:id,address_line1,address_line2,country_id,city_id,district_id,zone_id,building,block,floor,side,apartment,zip_code',
            'addresses.country:id,name',
            'addresses.city:id,name',
            'addresses.district:id,name',
            'addresses.zone:id,name',
            'billingAddresses:id,address_line1,address_line2,country_id,city_id,district_id,zone_id,building,block,floor,side,apartment,zip_code',
            'billingAddresses.country:id,name',
            'billingAddresses.city:id,name',
            'billingAddresses.district:id,name',
            'billingAddresses.zone:id,name',
            'shippingAddresses:id,address_line1,address_line2,country_id,city_id,district_id,zone_id,building,block,floor,side,apartment,zip_code',
            'shippingAddresses.country:id,name',
            'shippingAddresses.city:id,name',
            'shippingAddresses.district:id,name',
            'shippingAddresses.zone:id,name',
            'primaryBillingAddress:id,address_line1,address_line2,country_id,city_id,district_id,zone_id,building,block,floor,side,apartment,zip_code',
            'primaryBillingAddress.country:id,name',
            'primaryBillingAddress.city:id,name',
            'primaryBillingAddress.district:id,name',
            'primaryBillingAddress.zone:id,name',
            'primaryShippingAddress:id,address_line1,address_line2,country_id,city_id,district_id,zone_id,building,block,floor,side,apartment,zip_code',
            'primaryShippingAddress.country:id,name',
            'primaryShippingAddress.city:id,name',
            'primaryShippingAddress.district:id,name',
            'primaryShippingAddress.zone:id,name',
            'primaryContact:id,name,title,work_phone,mobile,position,extension,is_primary',
            'contacts:id,name,title,work_phone,mobile,position,extension,is_primary',
            'attachments:id,supplier_id,file_name,file_path,file_type,file_size,description,category,is_public,created_at,updated_at',
            // Opening balances with currency and payment terms
            'openingBalances:id,currency_id,opening_amount,opening_date,notes,payment_term_id,payment_method_id,allow_credit,payment_day,track_payment,settlement_method,is_active,currency_id',
            'openingBalances.currency:id,code,name,iso_code',
            'openingBalances.paymentTerm:id,code,name,nb_days',
            'openingBalances.paymentMethod:id,code,name',
            // Credit limits with currency
            'creditLimits:id,currency_id,credit_limit,used_credit,available_credit,notes,is_active',
            'creditLimits.currency:id,code,name,iso_code',
            // Cheque limits with currency
            'chequeLimits:id,currency_id,max_cheques,used_cheques,available_cheques,notes,is_active',
            'chequeLimits.currency:id,code,name,iso_code',
        ]);

        // Load active opening balances with currency and payment terms
        $openingBalances = $supplier->openingBalances()
            ->where('is_active', true)
            ->with([
                'currency:id,code,name,iso_code',
                'paymentTerm:id,code,name,nb_days',
                'paymentMethod:id,code,name',
            ])
            ->get();

        // Load active credit limits and cheque limits with currency (like customer controller)
        $creditLimits = $supplier->creditLimits()
            ->where('is_active', true)
            ->with('currency:id,code,name,iso_code')
            ->get();
        $chequeLimits = $supplier->chequeLimits()
            ->where('is_active', true)
            ->with('currency:id,code,name,iso_code')
            ->get();

        $payload = [
            'supplier' => $supplier,
            'openingBalances' => $openingBalances,
            'creditLimits' => $creditLimits,
            'chequeLimits' => $chequeLimits,
        ];

        return (new SupplierFullResource($payload))->toArray(request());
    }
}
