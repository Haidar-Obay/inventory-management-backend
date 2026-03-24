<?php

declare(strict_types=1);

namespace App\Http\Resources\Supplier;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * Expects resource to be array with: supplier, creditLimits, chequeLimits, openingBalances.
 *
 * @phpstan-type Payload array{supplier: \App\Models\Supplier, creditLimits: Collection, chequeLimits: Collection, openingBalances: Collection}
 */
class SupplierFullResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array{supplier: \App\Models\Supplier, creditLimits: Collection, chequeLimits: Collection, openingBalances: Collection} $payload */
        $payload = $this->resource;
        $supplier = $payload['supplier'];
        $openingBalances = $payload['openingBalances'];
        $creditLimits = $payload['creditLimits'];
        $chequeLimits = $payload['chequeLimits'];

        $primaryBilling = $supplier->primaryBillingAddress()->first();
        $primaryShipping = $supplier->primaryShippingAddress()->first();

        return [
            'id' => $supplier->id,
            'title' => $supplier->title,
            'first_name' => $supplier->first_name,
            'middle_name' => $supplier->middle_name,
            'last_name' => $supplier->last_name,
            'display_name' => $supplier->display_name,
            'company_name' => $supplier->company_name,
            'phone1' => $supplier->phone1,
            'phone2' => $supplier->phone2,
            'phone3' => $supplier->phone3,
            'file_number' => $supplier->file_number,
            'bar_code' => $supplier->bar_code,
            'search_terms' => $supplier->search_terms,
            'indicator' => $supplier->indicator,
            'invoicing_mode' => $supplier->invoicing_mode,
            'opening_amount' => $supplier->opening_amount,
            'opening_date' => $supplier->opening_date,
            'credit_limit' => $supplier->credit_limit,
            'accept_cheques' => $supplier->openingBalances()->active()->where('accept_cheques', true)->exists(),
            'max_cheques' => $supplier->max_cheques,
            'taxable' => $supplier->taxable,
            'taxed_from_date' => $supplier->taxed_from_date,
            'taxed_till_date' => $supplier->taxed_till_date,
            'subjected_to_tax' => $supplier->subjected_to_tax,
            'added_tax' => $supplier->added_tax,
            'exempted' => $supplier->exempted,
            'exempted_from' => $supplier->exempted_from,
            'exemption_reference' => $supplier->exemption_reference,
            'exempted_from_date' => $supplier->exempted_from_date,
            'exempted_till_date' => $supplier->exempted_till_date,
            'catalog' => $supplier->catalog,
            'is_foreign' => $supplier->is_foreign,
            'active' => $supplier->active,
            'message' => $supplier->message,
            'notes' => $supplier->notes,
            'created_at' => $supplier->created_at,
            'updated_at' => $supplier->updated_at,

            'supplier_group' => $supplier->supplierGroup ? [
                'id' => $supplier->supplierGroup->id,
                'name' => $supplier->supplierGroup->name,
                'code' => $supplier->supplierGroup->code,
                'active' => $supplier->supplierGroup->active,
            ] : null,
            'trade' => $supplier->trade ? [
                'id' => $supplier->trade->id,
                'name' => $supplier->trade->name,
                'code' => $supplier->trade->code,
                'active' => $supplier->trade->active,
            ] : null,
            'business_type' => $supplier->businessType ? [
                'id' => $supplier->businessType->id,
                'name' => $supplier->businessType->name,
                'code' => $supplier->businessType->code,
                'active' => $supplier->businessType->active,
            ] : null,
            'payment_term' => $supplier->openingBalances->first()?->paymentTerm ? [
                'id' => $supplier->openingBalances->first()->paymentTerm->id,
                'code' => $supplier->openingBalances->first()->paymentTerm->code,
                'name' => $supplier->openingBalances->first()->paymentTerm->name,
                'nb_days' => $supplier->openingBalances->first()->paymentTerm->nb_days,
                'active' => $supplier->openingBalances->first()->paymentTerm->active,
            ] : null,
            'payment_method' => $supplier->openingBalances->first()?->paymentMethod ? [
                'id' => $supplier->openingBalances->first()->paymentMethod->id,
                'code' => $supplier->openingBalances->first()->paymentMethod->code,
                'name' => $supplier->openingBalances->first()->paymentMethod->name,
                'active' => $supplier->openingBalances->first()->paymentMethod->active,
            ] : null,
            'currency' => $supplier->currency ? [
                'id' => $supplier->currency->id,
                'code' => $supplier->currency->code,
                'name' => $supplier->currency->name,
                'iso_code' => $supplier->currency->iso_code,
                'active' => $supplier->currency->active,
            ] : null,

            'addresses' => $supplier->addresses->map(function ($address) {
                return [
                    'id' => $address->id,
                    'address_line1' => $address->address_line1,
                    'address_line2' => $address->address_line2,
                    'country_id' => $address->country_id,
                    'city_id' => $address->city_id,
                    'district_id' => $address->district_id,
                    'zone_id' => $address->zone_id,
                    'building' => $address->building,
                    'block' => $address->block,
                    'floor' => $address->floor,
                    'side' => $address->side,
                    'appartment' => $address->appartment,
                    'zip_code' => $address->zip_code,
                    'country' => $address->country ? [
                        'id' => $address->country->id,
                        'name' => $address->country->name,
                    ] : null,
                    'city' => $address->city ? [
                        'id' => $address->city->id,
                        'name' => $address->city->name,
                    ] : null,
                    'district' => $address->district ? [
                        'id' => $address->district->id,
                        'name' => $address->district->name,
                    ] : null,
                    'zone' => $address->zone ? [
                        'id' => $address->zone->id,
                        'name' => $address->zone->name,
                    ] : null,
                    'pivot' => $address->pivot,
                ];
            }),

            'billing_addresses' => $supplier->billingAddresses
                ->sortByDesc(fn ($address) => $address->pivot->is_primary)
                ->values()
                ->map(function ($address) {
                    return [
                        'id' => $address->id,
                        'address_line1' => $address->address_line1,
                        'address_line2' => $address->address_line2,
                        'country_id' => $address->country_id,
                        'city_id' => $address->city_id,
                        'district_id' => $address->district_id,
                        'zone_id' => $address->zone_id,
                        'building' => $address->building,
                        'block' => $address->block,
                        'floor' => $address->floor,
                        'side' => $address->side,
                        'appartment' => $address->appartment,
                        'zip_code' => $address->zip_code,
                        'country' => $address->country ? [
                            'id' => $address->country->id,
                            'name' => $address->country->name,
                        ] : null,
                        'city' => $address->city ? [
                            'id' => $address->city->id,
                            'name' => $address->city->name,
                        ] : null,
                        'district' => $address->district ? [
                            'id' => $address->district->id,
                            'name' => $address->district->name,
                        ] : null,
                        'zone' => $address->zone ? [
                            'id' => $address->zone->id,
                            'name' => $address->zone->name,
                        ] : null,
                    ];
                }),

            'shipping_addresses' => $supplier->shippingAddresses
                ->sortByDesc(fn ($address) => $address->pivot->is_primary)
                ->values()
                ->map(function ($address) {
                    return [
                        'id' => $address->id,
                        'address_line1' => $address->address_line1,
                        'address_line2' => $address->address_line2,
                        'country_id' => $address->country_id,
                        'city_id' => $address->city_id,
                        'district_id' => $address->district_id,
                        'zone_id' => $address->zone_id,
                        'building' => $address->building,
                        'block' => $address->block,
                        'floor' => $address->floor,
                        'side' => $address->side,
                        'appartment' => $address->appartment,
                        'zip_code' => $address->zip_code,
                        'country' => $address->country ? [
                            'id' => $address->country->id,
                            'name' => $address->country->name,
                        ] : null,
                        'city' => $address->city ? [
                            'id' => $address->city->id,
                            'name' => $address->city->name,
                        ] : null,
                        'district' => $address->district ? [
                            'id' => $address->district->id,
                            'name' => $address->district->name,
                        ] : null,
                        'zone' => $address->zone ? [
                            'id' => $address->zone->id,
                            'name' => $address->zone->name,
                        ] : null,
                    ];
                }),

            'primary_billing_address' => $primaryBilling ? [
                'id' => $primaryBilling->id,
                'address_line1' => $primaryBilling->address_line1,
                'address_line2' => $primaryBilling->address_line2,
                'country_id' => $primaryBilling->country_id,
                'city_id' => $primaryBilling->city_id,
                'district_id' => $primaryBilling->district_id,
                'zone_id' => $primaryBilling->zone_id,
                'building' => $primaryBilling->building,
                'block' => $primaryBilling->block,
                'floor' => $primaryBilling->floor,
                'side' => $primaryBilling->side,
                'appartment' => $primaryBilling->appartment,
                'zip_code' => $primaryBilling->zip_code,
                'country' => $primaryBilling->country ? [
                    'id' => $primaryBilling->country->id,
                    'name' => $primaryBilling->country->name,
                ] : null,
                'city' => $primaryBilling->city ? [
                    'id' => $primaryBilling->city->id,
                    'name' => $primaryBilling->city->name,
                ] : null,
                'district' => $primaryBilling->district ? [
                    'id' => $primaryBilling->district->id,
                    'name' => $primaryBilling->district->name,
                ] : null,
                'zone' => $primaryBilling->zone ? [
                    'id' => $primaryBilling->zone->id,
                    'name' => $primaryBilling->zone->name,
                ] : null,
            ] : null,

            'primary_shipping_address' => $primaryShipping ? [
                'id' => $primaryShipping->id,
                'address_line1' => $primaryShipping->address_line1,
                'address_line2' => $primaryShipping->address_line2,
                'country_id' => $primaryShipping->country_id,
                'city_id' => $primaryShipping->city_id,
                'district_id' => $primaryShipping->district_id,
                'zone_id' => $primaryShipping->zone_id,
                'building' => $primaryShipping->building,
                'block' => $primaryShipping->block,
                'floor' => $primaryShipping->floor,
                'side' => $primaryShipping->side,
                'appartment' => $primaryShipping->appartment,
                'zip_code' => $primaryShipping->zip_code,
                'country' => $primaryShipping->country ? [
                    'id' => $primaryShipping->country->id,
                    'name' => $primaryShipping->country->name,
                ] : null,
                'city' => $primaryShipping->city ? [
                    'id' => $primaryShipping->city->id,
                    'name' => $primaryShipping->city->name,
                ] : null,
                'district' => $primaryShipping->district ? [
                    'id' => $primaryShipping->district->id,
                    'name' => $primaryShipping->district->name,
                ] : null,
                'zone' => $primaryShipping->zone ? [
                    'id' => $primaryShipping->zone->id,
                    'name' => $primaryShipping->zone->name,
                ] : null,
            ] : null,

            'primary_contact' => $supplier->primaryContact ? [
                'id' => $supplier->primaryContact->id,
                'name' => $supplier->primaryContact->name,
                'title' => $supplier->primaryContact->title,
                'work_phone' => $supplier->primaryContact->work_phone,
                'mobile' => $supplier->primaryContact->mobile,
                'position' => $supplier->primaryContact->position,
                'extension' => $supplier->primaryContact->extension,
                'is_primary' => $supplier->primaryContact->is_primary,
            ] : null,

            'contacts' => $supplier->contacts()->get()->map(fn ($contact) => [
                'id' => $contact->id,
                'name' => $contact->name,
                'title' => $contact->title,
                'work_phone' => $contact->work_phone,
                'mobile' => $contact->mobile,
                'position' => $contact->position,
                'extension' => $contact->extension,
                'is_primary' => $contact->is_primary,
            ]),

            'attachments' => $supplier->attachments->map(fn ($attachment) => [
                'id' => $attachment->id,
                'file_name' => $attachment->file_name,
                'file_path' => $attachment->file_path,
                'file_type' => $attachment->file_type,
                'file_size' => $attachment->file_size,
                'description' => $attachment->description,
                'category' => $attachment->category,
                'is_public' => $attachment->is_public,
            ]),

            'opening_balances' => $openingBalances->isNotEmpty()
                ? $openingBalances->map(function ($openingBalance) {
                    return [
                        'id' => $openingBalance->id,
                        'currency_id' => $openingBalance->currency_id,
                        'currency_code' => optional($openingBalance->currency)->code,
                        'currency_name' => optional($openingBalance->currency)->name,
                        'currency_iso_code' => optional($openingBalance->currency)->iso_code,
                        'opening_amount' => $openingBalance->opening_amount,
                        'opening_date' => $openingBalance->opening_date,
                        'notes' => $openingBalance->notes,
                        'payment_term_id' => $openingBalance->payment_term_id,
                        'payment_method_id' => $openingBalance->payment_method_id,
                        'allow_credit' => $openingBalance->allow_credit,
                        'payment_day' => $openingBalance->payment_day,
                        'track_payment' => $openingBalance->track_payment,
                        'settlement_method' => $openingBalance->settlement_method,
                        'accept_cheques' => (bool) $openingBalance->accept_cheques,
                        'payment_term' => $openingBalance->paymentTerm ? [
                            'id' => $openingBalance->paymentTerm->id,
                            'code' => $openingBalance->paymentTerm->code,
                            'name' => $openingBalance->paymentTerm->name,
                            'nb_days' => $openingBalance->paymentTerm->nb_days,
                        ] : null,
                        'payment_method' => $openingBalance->paymentMethod ? [
                            'id' => $openingBalance->paymentMethod->id,
                            'code' => $openingBalance->paymentMethod->code,
                            'name' => $openingBalance->paymentMethod->name,
                        ] : null,
                        'is_active' => $openingBalance->is_active,
                    ];
                })
                : (
                    ! is_null($supplier->opening_amount)
                        ? collect([[
                            'id' => null,
                            'currency_id' => $supplier->currency_id,
                            'currency_code' => optional($supplier->currency)->code,
                            'currency_name' => optional($supplier->currency)->name,
                            'currency_iso_code' => optional($supplier->currency)->iso_code,
                            'opening_amount' => $supplier->opening_amount,
                            'opening_date' => $supplier->opening_date,
                            'notes' => null,
                            'is_active' => true,
                        ]])
                        : collect([])
                ),

            'credit_limits' => $creditLimits->map(fn ($creditLimit) => [
                'id' => $creditLimit->id,
                'currency_id' => $creditLimit->currency_id,
                'currency_code' => optional($creditLimit->currency)->code,
                'currency_name' => optional($creditLimit->currency)->name,
                'currency_iso_code' => optional($creditLimit->currency)->iso_code,
                'credit_limit' => $creditLimit->credit_limit,
                'used_credit' => $creditLimit->used_credit,
                'available_credit' => $creditLimit->available_credit,
                'notes' => $creditLimit->notes,
                'is_active' => $creditLimit->is_active,
            ]),

            'cheque_limits' => $chequeLimits->map(fn ($chequeLimit) => [
                'id' => $chequeLimit->id,
                'currency_id' => $chequeLimit->currency_id,
                'currency_code' => optional($chequeLimit->currency)->code,
                'currency_name' => optional($chequeLimit->currency)->name,
                'currency_iso_code' => optional($chequeLimit->currency)->iso_code,
                'max_cheques' => $chequeLimit->max_cheques,
                'used_cheques' => $chequeLimit->used_cheques,
                'available_cheques' => $chequeLimit->available_cheques,
                'notes' => $chequeLimit->notes,
                'is_active' => $chequeLimit->is_active,
            ]),
        ];
    }
}
