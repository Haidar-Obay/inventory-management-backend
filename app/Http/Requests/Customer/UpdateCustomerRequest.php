<?php

namespace App\Http\Requests\Customer;

use App\Models\PaymentMethod;
use App\Models\PaymentTerm;
use App\Models\Salesman;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;

class UpdateCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Override the validation behavior to handle FormData with 'data' field
     */
    protected function prepareForValidation()
    {
        // If this is FormData with a 'data' field, decode it and merge into request
        if ($this->has('data')) {
            $data = json_decode($this->input('data'), true);
            if (is_array($data)) {
                $this->merge($data);
            }
        }
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
            'search_terms' => 'sometimes|nullable|array',

            // Foreign key relationships
            'trade_id' => 'sometimes|nullable|exists:trades,id',
            'company_code_id' => 'sometimes|nullable|exists:company_codes,id',
            'customer_group_id' => 'sometimes|nullable|exists:customer_groups,id',
            'business_type_id' => 'sometimes|nullable|exists:business_types,id',
            'sales_channel_id' => 'sometimes|nullable|exists:sales_channels,id',
            'distribution_channel_id' => 'sometimes|nullable|exists:distribution_channels,id',
            'media_channel_id' => 'sometimes|nullable|exists:media_channels,id',
            'indicator' => 'sometimes|nullable|in:A,B,C,D',
            'risk_category' => 'sometimes|nullable|in:Low,Medium,High',

            // Salesmen relationships with active validation
            'salesman_id' => 'sometimes|nullable|exists:salesmen,id',
            'collector_id' => [
                'sometimes',
                'nullable',
                'exists:salesmen,id',
                function ($attribute, $value, $fail) {
                    if ($value) {
                        $salesman = Salesman::find($value);
                        if ($salesman && ! $salesman->active) {
                            $fail('The selected collector is inactive and cannot be assigned to a customer.');
                        }
                    }
                },
            ],
            'supervisor_id' => [
                'sometimes',
                'nullable',
                'exists:salesmen,id',
                function ($attribute, $value, $fail) {
                    if ($value) {
                        $salesman = Salesman::find($value);
                        if ($salesman && ! $salesman->active) {
                            $fail('The selected supervisor is inactive and cannot be assigned to a customer.');
                        }
                    }
                },
            ],
            'manager_id' => [
                'sometimes',
                'nullable',
                'exists:salesmen,id',
                function ($attribute, $value, $fail) {
                    if ($value) {
                        $salesman = Salesman::find($value);
                        if ($salesman && ! $salesman->active) {
                            $fail('The selected manager is inactive and cannot be assigned to a customer.');
                        }
                    }
                },
            ],

            // Payment relationships with active validation
            'selected_payment_term' => [
                'sometimes',
                'nullable',
                'exists:payment_terms,id',
                function ($attribute, $value, $fail) {
                    if ($value) {
                        $paymentTerm = PaymentTerm::find($value);
                        if ($paymentTerm && ! $paymentTerm->active) {
                            $fail('The selected payment term is inactive and cannot be assigned to a customer.');
                        }
                    }
                },
            ],
            'payment_term_id' => [
                'sometimes',
                'nullable',
                'exists:payment_terms,id',
                function ($attribute, $value, $fail) {
                    if ($value) {
                        $paymentTerm = PaymentTerm::find($value);
                        if ($paymentTerm && ! $paymentTerm->active) {
                            $fail('The selected payment term is inactive and cannot be assigned to a customer.');
                        }
                    }
                },
            ],
            'selected_payment_method' => [
                'sometimes',
                'nullable',
                'exists:payment_methods,id',
                function ($attribute, $value, $fail) {
                    if ($value) {
                        $paymentMethod = PaymentMethod::find($value);
                        if ($paymentMethod && ! $paymentMethod->active) {
                            $fail('The selected payment method is inactive and cannot be assigned to a customer.');
                        }
                    }
                },
            ],
            'payment_method_id' => [
                'sometimes',
                'nullable',
                'exists:payment_methods,id',
                function ($attribute, $value, $fail) {
                    if ($value) {
                        $paymentMethod = PaymentMethod::find($value);
                        if ($paymentMethod && ! $paymentMethod->active) {
                            $fail('The selected payment method is inactive and cannot be assigned to a customer.');
                        }
                    }
                },
            ],
            'allow_credit' => 'sometimes|nullable|boolean',
            'accept_cheques' => 'sometimes|nullable|boolean',
            'payment_day' => 'sometimes|nullable|in:1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25,26,27,28,29,30',
            'track_payment' => 'sometimes|nullable|in:yes,no',
            'settlement_method' => 'sometimes|nullable|in:FIFO,Manual',

            // Pricing
            'price_choice' => 'sometimes|nullable|in:price1,price2,price3,price4,price5,price6,last_invoice_price',
            'price_list' => 'sometimes|nullable|string|max:255',
            'global_discount' => 'sometimes|nullable|numeric|min:0|max:100',
            'discount_class' => 'sometimes|nullable|in:Silver,Gold,Platinum',
            'markup' => 'sometimes|nullable|numeric|min:0|max:100',
            'markdown' => 'sometimes|nullable|numeric|min:0|max:100',

            // Tax
            'taxable' => 'sometimes|nullable|boolean',
            'taxed_from_date' => [
                'sometimes',
                'nullable',
                'date',
                function ($attribute, $value, $fail) {
                    $taxable = $this->input('taxable');
                    if ($value && $taxable === false) {
                        $fail('Taxed from date cannot be set when customer is not taxable.');
                    }
                },
            ],
            'taxed_till_date' => [
                'sometimes',
                'nullable',
                'date',
                'after_or_equal:taxed_from_date',
                function ($attribute, $value, $fail) {
                    $taxable = $this->input('taxable');
                    if ($value && $taxable === false) {
                        $fail('Taxed till date cannot be set when customer is not taxable.');
                    }
                },
            ],
            'subjected_to_tax' => [
                'sometimes',
                'nullable',
                'boolean',
                function ($attribute, $value, $fail) {
                    $taxable = $this->input('taxable');
                    if ($value && $taxable === false) {
                        $fail('Subjected to tax cannot be set when customer is not taxable.');
                    }
                },
            ],
            'added_tax' => [
                'sometimes',
                'nullable',
                'numeric',
                'min:0',
                'max:100',
                function ($attribute, $value, $fail) {
                    $taxable = $this->input('taxable');
                    $subjectedToTax = $this->input('subjected_to_tax');
                    if ($value && $taxable === false) {
                        $fail('Added tax cannot be set when customer is not taxable.');
                    }
                    if ($value && $subjectedToTax === false) {
                        $fail('Added tax cannot be set when customer is not subjected to tax.');
                    }
                },
            ],
            'exempted' => [
                'sometimes',
                'nullable',
                'boolean',
                function ($attribute, $value, $fail) {
                    $taxable = $this->input('taxable');
                    if ($value && $taxable === false) {
                        $fail('Tax exemption cannot be set when customer is not taxable.');
                    }
                },
            ],
            'exempted_from' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
                function ($attribute, $value, $fail) {
                    $taxable = $this->input('taxable');
                    $exempted = $this->input('exempted');
                    if ($value && $taxable === false) {
                        $fail('Exemption reason cannot be set when customer is not taxable.');
                    }
                    if ($value && $exempted === false) {
                        $fail('Exemption reason cannot be set when customer is not exempted.');
                    }
                },
            ],
            'exemption_reference' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
                function ($attribute, $value, $fail) {
                    $taxable = $this->input('taxable');
                    $exempted = $this->input('exempted');
                    if ($value && $taxable === false) {
                        $fail('Exemption reference cannot be set when customer is not taxable.');
                    }
                    if ($value && $exempted === false) {
                        $fail('Exemption reference cannot be set when customer is not exempted.');
                    }
                },
            ],
            'exempted_from_date' => [
                'sometimes',
                'nullable',
                'date',
                function ($attribute, $value, $fail) {
                    $taxable = $this->input('taxable');
                    $exempted = $this->input('exempted');
                    if ($value && $taxable === false) {
                        $fail('Exemption start date cannot be set when customer is not taxable.');
                    }
                    if ($value && $exempted === false) {
                        $fail('Exemption start date cannot be set when customer is not exempted.');
                    }
                },
            ],
            'exempted_till_date' => [
                'sometimes',
                'nullable',
                'date',
                'after_or_equal:exempted_from_date',
                function ($attribute, $value, $fail) {
                    $taxable = $this->input('taxable');
                    $exempted = $this->input('exempted');
                    if ($value && $taxable === false) {
                        $fail('Exemption end date cannot be set when customer is not taxable.');
                    }
                    if ($value && $exempted === false) {
                        $fail('Exemption end date cannot be set when customer is not exempted.');
                    }
                },
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
            'showMessageField' => 'sometimes|nullable|boolean',
            'message' => 'sometimes|nullable|string|max:1000',

            // Primary contact
            'contacts_id' => 'sometimes|nullable|exists:customer_contacts,id',

            // Notes
            'notes' => 'sometimes|nullable|string',

            // Addresses (handled separately in controller)
            'addresses' => 'sometimes|array|min:1',
            'addresses.*.address_line1' => 'required|string|max:255',
            'addresses.*.address_line2' => 'nullable|string|max:255',
            'addresses.*.country_id' => 'nullable|exists:countries,id',
            'addresses.*.city_id' => 'nullable|exists:cities,id',
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

            // Shipping addresses array
            'shipping_addresses' => 'sometimes|nullable|array',
            'shipping_addresses.*.address_line1' => 'required|string|max:255',
            'shipping_addresses.*.address_line2' => 'nullable|string|max:255',
            'shipping_addresses.*.country_id' => 'nullable|exists:countries,id',
            'shipping_addresses.*.city_id' => 'nullable|exists:cities,id',
            'shipping_addresses.*.district_id' => 'nullable|exists:districts,id',
            'shipping_addresses.*.zone_id' => 'nullable|exists:zones,id',
            'shipping_addresses.*.building' => 'nullable|string|max:255',
            'shipping_addresses.*.block' => 'nullable|string|max:255',
            'shipping_addresses.*.floor' => 'nullable|string|max:255',
            'shipping_addresses.*.side' => 'nullable|string|max:255',
            'shipping_addresses.*.apartment' => 'nullable|string|max:255',
            'shipping_addresses.*.zip_code' => 'nullable|string|max:20',

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

            // Credit limits validation
            'credit_limits' => 'sometimes|array',
            'credit_limits.*.currency_id' => 'required|exists:currencies,id',
            'credit_limits.*.credit_limit' => 'required|numeric|min:0',
            'credit_limits.*.notes' => 'nullable|string',

            // Cheque limits validation
            'cheque_limits' => 'sometimes|array',
            'cheque_limits.*.currency_id' => 'required|exists:currencies,id',
            'cheque_limits.*.max_cheques' => 'required|integer|min:0',
            'cheque_limits.*.notes' => 'nullable|string',

            // Opening balances validation
            'opening_balances' => 'sometimes|array',
            'opening_balances.*.currency_id' => 'required|exists:currencies,id',
            'opening_balances.*.opening_amount' => 'required|numeric',
            'opening_balances.*.opening_date' => 'nullable|date',
            'opening_balances.*.notes' => 'nullable|string',

            // Contacts validation
            'contacts' => 'sometimes|nullable|array',
            'contacts.*.title' => 'sometimes|nullable|string|max:255',
            'contacts.*.name' => 'sometimes|required|string|max:255',
            'contacts.*.work_phone' => 'sometimes|nullable|string|max:20',
            'contacts.*.mobile' => 'sometimes|nullable|string|max:20',
            'contacts.*.position' => 'sometimes|nullable|string|max:255',
            'contacts.*.extension' => 'sometimes|nullable|string|max:20',
            'contacts.*.is_primary' => 'sometimes|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'addresses.min' => 'At least one address must be provided.',
            'addresses.*.address_type.in' => 'Address type must be billing, shipping, or both.',
            'addresses.*.address_line1.required' => 'Address line 1 is required for each address.',

            // Credit limits messages
            'credit_limits.*.currency_id.required' => 'Currency is required for each credit limit.',
            'credit_limits.*.currency_id.exists' => 'Selected currency does not exist.',
            'credit_limits.*.credit_limit.required' => 'Credit limit amount is required.',
            'credit_limits.*.credit_limit.numeric' => 'Credit limit must be a number.',
            'credit_limits.*.credit_limit.min' => 'Credit limit must be at least 0.',

            // Cheque limits messages
            'cheque_limits.*.currency_id.required' => 'Currency is required for each cheque limit.',
            'cheque_limits.*.currency_id.exists' => 'Selected currency does not exist.',
            'cheque_limits.*.max_cheques.required' => 'Maximum cheques is required.',
            'cheque_limits.*.max_cheques.integer' => 'Maximum cheques must be a whole number.',
            'cheque_limits.*.max_cheques.min' => 'Maximum cheques must be at least 0.',

            // Opening balances messages
            'opening_balances.*.currency_id.required' => 'Currency is required for each opening balance.',
            'opening_balances.*.currency_id.exists' => 'Selected currency does not exist.',
            'opening_balances.*.opening_amount.required' => 'Opening amount is required.',
            'opening_balances.*.opening_amount.numeric' => 'Opening amount must be a number.',
            'opening_balances.*.opening_date.date' => 'Opening date must be a valid date.',

            // Contacts messages
            'contacts.*.name.required' => 'Contact name is required.',
            'contacts.*.name.max' => 'Contact name cannot exceed 255 characters.',
            'contacts.*.work_phone.max' => 'Work phone cannot exceed 20 characters.',
            'contacts.*.mobile.max' => 'Mobile phone cannot exceed 20 characters.',
            'contacts.*.position.max' => 'Position cannot exceed 255 characters.',
            'contacts.*.extension.max' => 'Extension cannot exceed 20 characters.',
        ];
    }
}
