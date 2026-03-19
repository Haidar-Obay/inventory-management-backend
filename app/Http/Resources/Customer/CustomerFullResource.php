<?php

declare(strict_types=1);

namespace App\Http\Resources\Customer;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerFullResource extends JsonResource
{
    /**
     * Expects resource to be array with: customer, creditLimits, chequeLimits, openingBalances.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $customer = $this->resource['customer'];
        $creditLimits = $this->resource['creditLimits'];
        $chequeLimits = $this->resource['chequeLimits'];
        $openingBalances = $this->resource['openingBalances'];

        $primaryBilling = $customer->primaryBillingAddress()->first();
        $primaryShipping = $customer->primaryShippingAddress()->first();

        return [
            'id' => $customer->id,
            'title' => $customer->title,
            'first_name' => $customer->first_name,
            'middle_name' => $customer->middle_name,
            'last_name' => $customer->last_name,
            'display_name' => $customer->display_name,
            'company_name' => $customer->company_name,
            'phone1' => $customer->phone1,
            'phone2' => $customer->phone2,
            'phone3' => $customer->phone3,
            'email' => $customer->email,
            'date_of_birth' => $customer->date_of_birth,
            'place_of_birth' => $customer->place_of_birth,
            'gender' => $customer->gender,
            'blood_type' => $customer->blood_type,
            'marital_status' => $customer->marital_status,
            'card_number' => $customer->card_number,
            'file_number' => $customer->file_number,
            'bar_code' => $customer->bar_code,
            'search_terms' => $customer->search_terms,
            'indicator' => $customer->indicator,
            'risk_category' => $customer->risk_category,
            'active' => $customer->active,
            'black_listed' => $customer->black_listed,
            'blacklisted_reason' => $customer->blacklisted_reason,
            'one_time_account' => $customer->one_time_account,
            'cash_customer' => $customer->cash_customer,
            'special_account' => $customer->special_account,
            'pos_customer' => $customer->pos_customer,
            'free_delivery_charge' => $customer->free_delivery_charge,
            'print_invoice_language' => $customer->print_invoice_language,
            'send_invoice' => $customer->send_invoice,
            'message' => $customer->message,
            'notes' => $customer->notes,
            'created_at' => $customer->created_at,
            'updated_at' => $customer->updated_at,
            'allow_credit' => $customer->openingBalances()->active()->where('allow_credit', true)->exists(),
            'accept_cheques' => $customer->openingBalances()->active()->where('accept_cheques', true)->exists(),
            'price_choice' => $customer->price_choice,
            'price_list' => $customer->price_list,
            'global_discount' => $customer->global_discount,
            'discount_class' => $customer->discount_class,
            'markup_percentage' => $customer->markup_percentage,
            'markdown_percentage' => $customer->markdown_percentage,
            'taxable' => $customer->taxable,
            'taxed_from_date' => $customer->taxed_from_date,
            'taxed_till_date' => $customer->taxed_till_date,
            'subjected_to_tax' => $customer->subjected_to_tax,
            'added_tax' => $customer->added_tax,
            'exempted' => $customer->exempted,
            'exempted_from' => $customer->exempted_from,
            'exemption_reference' => $customer->exemption_reference,
            'exempted_from_date' => $customer->exempted_from_date,
            'exempted_till_date' => $customer->exempted_till_date,
            'customer_group' => $customer->customerGroup ? [
                'id' => $customer->customerGroup->id,
                'name' => $customer->customerGroup->name,
            ] : null,
            'salesman' => $customer->salesman ? [
                'id' => $customer->salesman->id,
                'name' => $customer->salesman->name,
            ] : null,
            'collector' => $customer->collector ? [
                'id' => $customer->collector->id,
                'name' => $customer->collector->name,
            ] : null,
            'supervisor' => $customer->supervisor ? [
                'id' => $customer->supervisor->id,
                'name' => $customer->supervisor->name,
            ] : null,
            'manager' => $customer->manager ? [
                'id' => $customer->manager->id,
                'name' => $customer->manager->name,
            ] : null,
            'payment_term' => $openingBalances->first()?->paymentTerm ? [
                'id' => $openingBalances->first()->paymentTerm->id,
                'code' => $openingBalances->first()->paymentTerm->code,
            ] : null,
            'payment_method' => $openingBalances->first()?->paymentMethod ? [
                'id' => $openingBalances->first()->paymentMethod->id,
                'code' => $openingBalances->first()->paymentMethod->code,
            ] : null,
            'trade' => $customer->trade ? [
                'id' => $customer->trade->id,
                'name' => $customer->trade->name,
            ] : null,
            'company_code' => $customer->companyCode ? [
                'id' => $customer->companyCode->id,
                'code' => $customer->companyCode->code,
            ] : null,
            'business_type' => $customer->businessType ? [
                'id' => $customer->businessType->id,
                'name' => $customer->businessType->name,
            ] : null,
            'sales_channel' => $customer->salesChannel ? [
                'id' => $customer->salesChannel->id,
                'name' => $customer->salesChannel->name,
            ] : null,
            'distribution_channel' => $customer->distributionChannel ? [
                'id' => $customer->distributionChannel->id,
                'name' => $customer->distributionChannel->name,
            ] : null,
            'media_channel' => $customer->mediaChannel ? [
                'id' => $customer->mediaChannel->id,
                'name' => $customer->mediaChannel->name,
            ] : null,
            'media_type' => $customer->mediaType ? [
                'id' => $customer->mediaType->id,
                'name' => $customer->mediaType->name,
            ] : null,
            'referral' => $customer->referral ? [
                'id' => $customer->referral->id,
                'name' => $customer->referral->name,
            ] : null,
            'associations' => $customer->associations->map(fn ($a) => [
                'id' => $a->id,
                'name' => $a->name,
            ]),
            'status' => $customer->status,
            'addresses' => $customer->addresses->map(fn ($address) => [
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
            ]),
            'billing_addresses' => $customer->billingAddresses
                ->sortByDesc(fn ($a) => $a->pivot->is_primary)
                ->values()
                ->map(fn ($address) => [
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
                ]),
            'shipping_addresses' => $customer->shippingAddresses
                ->sortByDesc(fn ($a) => $a->pivot->is_primary)
                ->values()
                ->map(fn ($address) => [
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
                ]),
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
            ] : null,
            'contacts' => $customer->contacts()->get()->map(fn ($contact) => [
                'id' => $contact->id,
                'title' => $contact->title,
                'name' => $contact->name,
                'work_phone' => $contact->work_phone,
                'mobile' => $contact->mobile,
                'position' => $contact->position,
                'extension' => $contact->extension,
                'is_primary' => $contact->is_primary,
            ]),
            'primary_contact' => $customer->primaryContact ? [
                'id' => $customer->primaryContact->id,
                'title' => $customer->primaryContact->title,
                'name' => $customer->primaryContact->name,
                'work_phone' => $customer->primaryContact->work_phone,
                'mobile' => $customer->primaryContact->mobile,
                'position' => $customer->primaryContact->position,
                'extension' => $customer->primaryContact->extension,
            ] : null,
            'credit_limits' => $creditLimits->map(fn ($limit) => [
                'id' => $limit->id,
                'currency_id' => $limit->currency_id,
                'currency_code' => $limit->currency->code,
                'currency_name' => $limit->currency->name,
                'currency_iso_code' => $limit->currency->iso_code,
                'credit_limit' => $limit->credit_limit,
                'notes' => $limit->notes,
                'is_active' => $limit->is_active,
            ]),
            'cheque_limits' => $chequeLimits->map(fn ($limit) => [
                'id' => $limit->id,
                'currency_id' => $limit->currency_id,
                'currency_code' => $limit->currency->code,
                'currency_name' => $limit->currency->name,
                'currency_iso_code' => $limit->currency->iso_code,
                'max_cheques' => $limit->max_cheques,
                'notes' => $limit->notes,
                'is_active' => $limit->is_active,
            ]),
            'opening_balances' => $openingBalances->map(fn ($ob) => [
                'id' => $ob->id,
                'currency_id' => $ob->currency_id,
                'currency_code' => $ob->currency->code,
                'currency_name' => $ob->currency->name,
                'currency_iso_code' => $ob->currency->iso_code,
                'opening_amount' => $ob->opening_amount,
                'opening_date' => $ob->opening_date,
                'notes' => $ob->notes,
                'payment_term_id' => $ob->payment_term_id,
                'payment_method_id' => $ob->payment_method_id,
                'allow_credit' => $ob->allow_credit,
                'payment_day' => $ob->payment_day,
                'track_payment' => $ob->track_payment,
                'settlement_method' => $ob->settlement_method,
                'accept_cheques' => (bool) $ob->accept_cheques,
                'payment_term' => $ob->paymentTerm ? [
                    'id' => $ob->paymentTerm->id,
                    'code' => $ob->paymentTerm->code,
                    'name' => $ob->paymentTerm->name,
                    'nb_days' => $ob->paymentTerm->nb_days,
                ] : null,
                'payment_method' => $ob->paymentMethod ? [
                    'id' => $ob->paymentMethod->id,
                    'code' => $ob->paymentMethod->code,
                    'name' => $ob->paymentMethod->name,
                ] : null,
                'is_active' => $ob->is_active,
            ]),
            'attachments' => $customer->attachments->map(fn ($attachment) => [
                'id' => $attachment->id,
                'file_name' => $attachment->file_name,
                'file_path' => $attachment->file_path,
                'file_type' => $attachment->file_type,
                'file_size' => $attachment->file_size,
                'description' => $attachment->description,
                'category' => $attachment->category,
                'is_public' => (bool) $attachment->is_public,
            ]),
        ];
    }
}
