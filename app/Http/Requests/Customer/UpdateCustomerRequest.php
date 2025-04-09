<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
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
            'billing_address_id' => 'required|exists:addresses,id',
            'shipping_address_id' => 'required|exists:addresses,id',
            'is_sub_customer' => 'boolean',
            'parent_customer_id' => 'nullable|exists:customers,id',
            'customer_group_id' => 'nullable|exists:customer_groups,id',
            'salesman_id' => 'nullable|exists:salesmen,id',
            'refer_by_id' => 'nullable|exists:refer_bies,id',
            'primary_payment_method_id' => 'nullable|exists:payment_methods,id',
            'payment_term_id' => 'nullable|exists:payment_terms,id',
            'credit_limit' => 'nullable|numeric|min:0',
            'tax_rule' => 'nullable|string|max:255',
            'tax_registration' => 'nullable|string|max:255',
            'opening_currency_id' => 'nullable|exists:currencies,id',
            'opening_balance' => 'nullable|numeric',
            'notes' => 'nullable|string',
            'attachments' => 'nullable|string',
            'is_inactive' => 'boolean',
        ];
    }
}
