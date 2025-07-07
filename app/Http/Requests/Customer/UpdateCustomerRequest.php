<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Models\Salesman;
use App\Models\PaymentTerm;
use App\Models\PaymentMethod;

class UpdateCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $customerId = $this->route('customer'); // Adjust this if your route parameter is named differently

        return [
            'title' => 'sometimes|nullable|string|max:255',
            'first_name' => 'sometimes|required|string|max:255',
            'middle_name' => 'sometimes|nullable|string|max:255',
            'last_name' => 'sometimes|required|string|max:255',
            'display_name' => 'sometimes|nullable|string|max:255',
            'company_name' => 'sometimes|nullable|string|max:255',

            'phone1' => [
                'sometimes',
                'nullable',
                'string',
                'max:20',
                Rule::unique('customers', 'phone1')->ignore($customerId),
            ],
            'phone2' => [
                'sometimes',
                'nullable',
                'string',
                'max:20',
                Rule::unique('customers', 'phone2')->ignore($customerId),
            ],
            'phone3' => [
                'sometimes',
                'nullable',
                'string',
                'max:20',
                Rule::unique('customers', 'phone3')->ignore($customerId),
            ],
            'file_number' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
                Rule::unique('customers', 'file_number')->ignore($customerId),
            ],
            'bar_code' => 'sometimes|nullable|string|max:255',
            'search_terms' => 'sometimes|nullable|string',

            // Foreign key relationships
            'trade_id' => 'sometimes|nullable|exists:trades,id',
            'company_code_id' => 'sometimes|nullable|exists:company_codes,id',
            'customer_group_id' => 'sometimes|nullable|exists:customer_groups,id',
            'business_type_id' => 'sometimes|nullable|exists:business_types,id',
            'sales_channel_id' => 'sometimes|nullable|exists:sales_channels,id',
            'distribution_channel_id' => 'sometimes|nullable|exists:distribution_channels,id',
            'media_channel_id' => 'sometimes|nullable|exists:media_channels,id',
            'indicator' => 'sometimes|nullable|in:A,B,C,D',
            'risk_category' => 'sometimes|nullable|in:A,B,C,D',

            // Salesmen relationships with active validation
            'salesman_id' => 'sometimes|nullable|exists:salesmen,id',
            'collector_id' => [
                'sometimes',
                'nullable',
                'exists:salesmen,id',
                function ($attribute, $value, $fail) {
                    if ($value) {
                        $salesman = Salesman::find($value);
                        if ($salesman && !$salesman->active) {
                            $fail('The selected collector is inactive and cannot be assigned to a customer.');
                        }
                    }
                }
            ],
            'supervisor_id' => [
                'sometimes',
                'nullable',
                'exists:salesmen,id',
                function ($attribute, $value, $fail) {
                    if ($value) {
                        $salesman = Salesman::find($value);
                        if ($salesman && !$salesman->active) {
                            $fail('The selected supervisor is inactive and cannot be assigned to a customer.');
                        }
                    }
                }
            ],
            'manager_id' => [
                'sometimes',
                'nullable',
                'exists:salesmen,id',
                function ($attribute, $value, $fail) {
                    if ($value) {
                        $salesman = Salesman::find($value);
                        if ($salesman && !$salesman->active) {
                            $fail('The selected manager is inactive and cannot be assigned to a customer.');
                        }
                    }
                }
            ],

            // Payment relationships with active validation
            'payment_term_id' => [
                'sometimes',
                'nullable',
                'exists:payment_terms,id',
                function ($attribute, $value, $fail) {
                    if ($value) {
                        $paymentTerm = PaymentTerm::find($value);
                        if ($paymentTerm && !$paymentTerm->active) {
                            $fail('The selected payment term is inactive and cannot be assigned to a customer.');
                        }
                    }
                }
            ],
            'payment_method_id' => [
                'sometimes',
                'nullable',
                'exists:payment_methods,id',
                function ($attribute, $value, $fail) {
                    if ($value) {
                        $paymentMethod = PaymentMethod::find($value);
                        if ($paymentMethod && !$paymentMethod->active) {
                            $fail('The selected payment method is inactive and cannot be assigned to a customer.');
                        }
                    }
                }
            ],
            'allow_credit' => 'sometimes|nullable|boolean',
            'accept_cheque' => 'sometimes|nullable|boolean',
            'payment_day' => 'sometimes|nullable|in:1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25,26,27,28,29,30',
            'track_payment' => 'sometimes|nullable|in:yes,no',
            'settlement_method' => 'sometimes|nullable|in:FIFO,Manual',

            // Pricing
            'pricing_choice' => 'sometimes|nullable|in:price1,price2,price3,price4,price5,price6,last_invoice_price',
            'discount_by_item' => 'sometimes|nullable|numeric|min:0|max:100',
            'global_discount' => 'sometimes|nullable|numeric|min:0|max:100',
            'discount_class' => 'sometimes|nullable|in:Silver,Gold,Platinum',
            'markup_percentage' => 'sometimes|nullable|numeric|min:0|max:100',
            'markdown_percentage' => 'sometimes|nullable|numeric|min:0|max:100',

            // Tax
            'taxable' => 'sometimes|nullable|boolean',
            'tax_rate' => [
                'sometimes',
                'nullable',
                'numeric',
                'min:0',
                'max:100',
                function ($attribute, $value, $fail) {
                    $taxable = $this->input('taxable');
                    if ($value && $taxable === false) {
                        $fail('Tax rate cannot be set when customer is not taxable.');
                    }
                }
            ],
            'tax_number' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
                function ($attribute, $value, $fail) {
                    $taxable = $this->input('taxable');
                    if ($value && $taxable === false) {
                        $fail('Tax number cannot be set when customer is not taxable.');
                    }
                }
            ],
            'is_exempted' => [
                'sometimes',
                'nullable',
                'boolean',
                function ($attribute, $value, $fail) {
                    $taxable = $this->input('taxable');
                    if ($value && $taxable === false) {
                        $fail('Tax exemption cannot be set when customer is not taxable.');
                    }
                }
            ],
            'exemption_from' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
                function ($attribute, $value, $fail) {
                    $taxable = $this->input('taxable');
                    $isExempted = $this->input('is_exempted');
                    if ($value && $taxable === false) {
                        $fail('Exemption reason cannot be set when customer is not taxable.');
                    }
                    if ($value && $isExempted === false) {
                        $fail('Exemption reason cannot be set when customer is not exempted.');
                    }
                }
            ],
            'exemption_reference' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
                function ($attribute, $value, $fail) {
                    $taxable = $this->input('taxable');
                    $isExempted = $this->input('is_exempted');
                    if ($value && $taxable === false) {
                        $fail('Exemption reference cannot be set when customer is not taxable.');
                    }
                    if ($value && $isExempted === false) {
                        $fail('Exemption reference cannot be set when customer is not exempted.');
                    }
                }
            ],
            'exempted_from_date' => [
                'sometimes',
                'nullable',
                'date',
                function ($attribute, $value, $fail) {
                    $taxable = $this->input('taxable');
                    $isExempted = $this->input('is_exempted');
                    if ($value && $taxable === false) {
                        $fail('Exemption start date cannot be set when customer is not taxable.');
                    }
                    if ($value && $isExempted === false) {
                        $fail('Exemption start date cannot be set when customer is not exempted.');
                    }
                }
            ],
            'exempted_till_date' => [
                'sometimes',
                'nullable',
                'date',
                'after_or_equal:exempted_from_date',
                function ($attribute, $value, $fail) {
                    $taxable = $this->input('taxable');
                    $isExempted = $this->input('is_exempted');
                    if ($value && $taxable === false) {
                        $fail('Exemption end date cannot be set when customer is not taxable.');
                    }
                    if ($value && $isExempted === false) {
                        $fail('Exemption end date cannot be set when customer is not exempted.');
                    }
                }
            ],

            // Status flags
            'active' => 'sometimes|nullable|boolean',
            'black_listed' => 'sometimes|nullable|boolean',
            'one_time_account' => 'sometimes|nullable|boolean',
            'special_account' => 'sometimes|nullable|boolean',
            'pos_customer' => 'sometimes|nullable|boolean',
            'free_delivery_charge' => 'sometimes|nullable|boolean',
            'print_invoice_language' => 'sometimes|nullable|in:English,Arabic',
            'send_invoice' => 'sometimes|nullable|in:email,sms,whatsapp,all',

            // Message functionality
            'add_message' => 'sometimes|nullable|boolean',
            'invoice_message' => 'sometimes|nullable|string|max:1000',

            // Primary contact
            'contacts_id' => 'sometimes|nullable|exists:customer_contacts,id',

            // Notes
            'notes' => 'sometimes|nullable|string',

            // Addresses (handled separately in controller)
            'addresses' => 'sometimes|array|min:1',
            'addresses.*.address_line1' => 'required|string|max:255',
            'addresses.*.address_line2' => 'nullable|string|max:255',
            'addresses.*.country_id' => 'required|exists:countries,id',
            'addresses.*.city_id' => 'required|exists:cities,id',
            'addresses.*.district_id' => 'nullable|exists:districts,id',
            'addresses.*.zone_id' => 'nullable|exists:zones,id',
            'addresses.*.building' => 'nullable|string|max:255',
            'addresses.*.block' => 'nullable|string|max:255',
            'addresses.*.floor' => 'nullable|string|max:255',
            'addresses.*.side' => 'nullable|string|max:255',
            'addresses.*.appartment' => 'nullable|string|max:255',
            'addresses.*.zip_code' => 'nullable|string|max:20',
            'addresses.*.address_type' => 'required|in:billing,shipping,both',
            'addresses.*.is_primary' => 'boolean',
            'addresses.*.address_name' => 'nullable|string|max:255',
            'addresses.*.notes' => 'nullable|string',

            // Legacy address fields for backward compatibility
            'billing_address.address_line1' => 'sometimes|nullable|string|max:255',
            'billing_address.address_line2' => 'sometimes|nullable|string|max:255',
            'billing_address.country_id' => 'sometimes|nullable|exists:countries,id',
            'billing_address.city_id' => 'sometimes|nullable|exists:cities,id',
            'billing_address.district_id' => 'sometimes|nullable|exists:districts,id',
            'billing_address.zone_id' => 'sometimes|nullable|exists:zones,id',
            'billing_address.building' => 'sometimes|nullable|string|max:255',
            'billing_address.block' => 'sometimes|nullable|string|max:255',
            'billing_address.floor' => 'sometimes|nullable|string|max:255',
            'billing_address.side' => 'sometimes|nullable|string|max:255',
            'billing_address.appartment' => 'sometimes|nullable|string|max:255',
            'billing_address.zip_code' => 'sometimes|nullable|string|max:20',

            'shipping_address.address_line1' => 'sometimes|nullable|string|max:255',
            'shipping_address.address_line2' => 'sometimes|nullable|string|max:255',
            'shipping_address.country_id' => 'sometimes|nullable|exists:countries,id',
            'shipping_address.city_id' => 'sometimes|nullable|exists:cities,id',
            'shipping_address.district_id' => 'sometimes|nullable|exists:districts,id',
            'shipping_address.zone_id' => 'sometimes|nullable|exists:zones,id',
            'shipping_address.building' => 'sometimes|nullable|string|max:255',
            'shipping_address.block' => 'sometimes|nullable|string|max:255',
            'shipping_address.floor' => 'sometimes|nullable|string|max:255',
            'shipping_address.side' => 'sometimes|nullable|string|max:255',
            'shipping_address.appartment' => 'sometimes|nullable|string|max:255',
            'shipping_address.zip_code' => 'sometimes|nullable|string|max:20',

            // Attachments (handled separately in controller)
            'attachments' => 'sometimes|nullable|array',
            'attachments.*' => 'file|mimes:jpg,jpeg,png,pdf,docx,xlsx,txt|max:5120',
        ];
    }

    public function messages(): array
    {
        return [
            'addresses.min' => 'At least one address must be provided.',
            'addresses.*.address_type.in' => 'Address type must be billing, shipping, or both.',
            'addresses.*.address_line1.required' => 'Address line 1 is required for each address.',
            'addresses.*.country_id.required' => 'Country is required for each address.',
            'addresses.*.city_id.required' => 'City is required for each address.',
        ];
    }
}
