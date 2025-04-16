<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => 'sometimes|nullable|string|max:255',
            'first_name' => 'sometimes|required|string|max:255',
            'middle_name' => 'sometimes|nullable|string|max:255',
            'last_name' => 'sometimes|required|string|max:255',
            'suffix' => 'sometimes|nullable|string|max:255',
            'display_name' => 'sometimes|nullable|string|max:255',
            'company_name' => 'sometimes|nullable|string|max:255',
            'phone1' => 'sometimes|nullable|string|max:20',
            'phone2' => 'sometimes|nullable|string|max:20',
            'phone3' => 'sometimes|nullable|string|max:20',
            'email' => 'sometimes|nullable|email|max:255',
            'website' => 'sometimes|nullable|url|max:255',
            'file_number' => 'sometimes|nullable|string|max:255',

            // Billing address
            'billing_address.address_line1' => 'sometimes|required|string|max:255',
            'billing_address.address_line2' => 'sometimes|nullable|string|max:255',
            'billing_address.country_id' => 'sometimes|required|exists:countries,id',
            'billing_address.city_id' => 'sometimes|required|exists:cities,id',
            'billing_address.province_id' => 'sometimes|required|exists:provinces,id',
            'billing_address.postal_code' => 'sometimes|nullable|string|max:255',
            'billing_address.complex' => 'sometimes|nullable|string|max:255',
            'billing_address.building' => 'sometimes|nullable|string|max:255',
            'billing_address.floor' => 'sometimes|nullable|string|max:255',
            'billing_address.suite' => 'sometimes|nullable|string|max:255',
            'billing_address.unit_number' => 'sometimes|nullable|string|max:255',

            // Shipping address
            'shipping_address.address_line1' => 'sometimes|required|string|max:255',
            'shipping_address.address_line2' => 'sometimes|nullable|string|max:255',
            'shipping_address.country_id' => 'sometimes|required|exists:countries,id',
            'shipping_address.city_id' => 'sometimes|required|exists:cities,id',
            'shipping_address.province_id' => 'sometimes|required|exists:provinces,id',
            'shipping_address.postal_code' => 'sometimes|nullable|string|max:255',
            'shipping_address.complex' => 'sometimes|nullable|string|max:255',
            'shipping_address.building' => 'sometimes|nullable|string|max:255',
            'shipping_address.floor' => 'sometimes|nullable|string|max:255',
            'shipping_address.suite' => 'sometimes|nullable|string|max:255',
            'shipping_address.unit_number' => 'sometimes|nullable|string|max:255',

            'is_sub_customer' => 'sometimes|boolean',
            'parent_customer_id' => 'sometimes|nullable|exists:customers,id',
            'customer_group_id' => 'sometimes|nullable|exists:customer_groups,id',
            'salesman_id' => 'sometimes|nullable|exists:salesmen,id',
            'refer_by_id' => 'sometimes|nullable|exists:refer_bies,id',

            // Reference existing payment method only
            'primary_payment_method_id' => 'sometimes|nullable|exists:payment_methods,id',

            // Allow inline creation for payment term
            'payment_term.name' => 'sometimes|required_with:payment_term|string|max:255',
            'payment_term.no_of_days' => 'sometimes|nullable|integer|min:0',
            'payment_term.is_inactive' => 'sometimes|nullable|boolean',

            'credit_limit' => 'sometimes|nullable|numeric|min:0',
            'taxable' => 'sometimes|nullable|boolean',
            'tax_registration' => 'sometimes|nullable|string|max:255',
            'opening_currency_id' => 'sometimes|nullable|exists:currencies,id',
            'opening_balance' => 'sometimes|nullable|numeric',
            'notes' => 'sometimes|nullable|string',

            'attachments' => 'sometimes|nullable|array',
            'attachments.*' => 'file|mimes:jpg,jpeg,png,pdf,docx,xlsx,txt|max:5120',
            
            'is_inactive' => 'sometimes|boolean',
        ];
    }
}
