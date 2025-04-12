<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

class StoreCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => 'nullable|string|max:255',
            'first_name' => 'required|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'last_name' => 'required|string|max:255',
            'suffix' => 'nullable|string|max:255',
            'display_name' => 'nullable|string|max:255',
            'company_name' => 'nullable|string|max:255',
            'phone1' => 'nullable|string|max:20',
            'phone2' => 'nullable|string|max:20',
            'phone3' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'website' => 'nullable|url|max:255',
            'file_number' => 'nullable|string|max:255',

            // Billing address fields
            'billing_address.address_line1' => 'required|string|max:255',
            'billing_address.address_line2' => 'nullable|string|max:255',
            'billing_address.country_id' => 'required|exists:countries,id',
            'billing_address.city_id' => 'required|exists:cities,id',
            'billing_address.province_id' => 'required|exists:provinces,id',
            'billing_address.postal_code' => 'nullable|string|max:20',
            'billing_address.complex' => 'nullable|string|max:255',
            'billing_address.building' => 'nullable|string|max:255',
            'billing_address.floor' => 'nullable|string|max:50',
            'billing_address.suite' => 'nullable|string|max:50',
            'billing_address.unit_number' => 'nullable|string|max:50',

            // Shipping address fields
            'shipping_address.address_line1' => 'required|string|max:255',
            'shipping_address.address_line2' => 'nullable|string|max:255',
            'shipping_address.country_id' => 'required|exists:countries,id',
            'shipping_address.city_id' => 'required|exists:cities,id',
            'shipping_address.province_id' => 'required|exists:provinces,id',
            'shipping_address.postal_code' => 'nullable|string|max:20',
            'shipping_address.complex' => 'nullable|string|max:255',
            'shipping_address.building' => 'nullable|string|max:255',
            'shipping_address.floor' => 'nullable|string|max:50',
            'shipping_address.suite' => 'nullable|string|max:50',
            'shipping_address.unit_number' => 'nullable|string|max:50',

            'is_sub_customer' => 'boolean',
            'parent_customer_id' => 'nullable|exists:customers,id',
            'customer_group_id' => 'nullable|exists:customer_groups,id',
            'salesman_id' => 'nullable|exists:salesmen,id',
            'refer_by_id' => 'nullable|exists:refer_bies,id',

            // Payment Method
            'payment_method.name' => 'required_with:payment_method|string|max:255',
            'payment_method.is_credit_card' => 'nullable|boolean',
            'payment_method.is_online_payment' => 'nullable|boolean',
            'payment_method.is_inactive' => 'nullable|boolean',

            // Payment Term
            'payment_term.name' => 'required_with:payment_term|string|max:255',
            'payment_term.no_of_days' => 'nullable|integer|min:0',
            'payment_term.is_inactive' => 'nullable|boolean',

            'credit_limit' => 'nullable|numeric|min:0',
            'taxable' => 'nullable|boolean',
            'tax_registration' => 'nullable|string|max:255',
            'opening_currency_id' => 'nullable|exists:currencies,id',
            'opening_balance' => 'nullable|numeric',
            'notes' => 'nullable|string',
            'attachments' => 'nullable|string',
            'is_inactive' => 'boolean',
        ];
    }
}
