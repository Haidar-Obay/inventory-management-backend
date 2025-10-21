<?php

namespace App\Http\Requests\Customer;

use App\Models\PaymentMethod;
use App\Models\PaymentTerm;
use App\Models\Salesman;
use Illuminate\Foundation\Http\FormRequest;

class StoreCustomerRequest extends FormRequest
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
        return [
            'title' => 'nullable|string|max:255',
            'first_name' => 'required|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'last_name' => 'required|string|max:255',
            'display_name' => 'nullable|string|max:255',
            'company_name' => 'nullable|string|max:255',
            'phone1' => 'required|string|max:20|unique:customers,phone1',
            'phone2' => 'nullable|string|max:20|unique:customers,phone2',
            'phone3' => 'nullable|string|max:20|unique:customers,phone3',
            'file_number' => 'nullable|string|max:255|unique:customers,file_number',
            'bar_code' => 'nullable|string|max:255',
            'search_terms' => 'nullable|array',

            // Foreign key relationships
            'trade_id' => 'nullable|exists:trades,id',
            'company_code_id' => 'nullable|exists:company_codes,id',
            'customer_group_id' => 'nullable|exists:customer_groups,id',
            'business_type_id' => 'nullable|exists:business_types,id',
            'sales_channel_id' => 'nullable|exists:sales_channels,id',
            'distribution_channel_id' => 'nullable|exists:distribution_channels,id',
            'media_channel_id' => 'nullable|exists:media_channels,id',
            'indicator' => 'nullable|in:A,B,C,D',
            'risk_category' => 'nullable|in:Low,Medium,High',

            // Salesmen relationships with active validation
            'salesman_id' => 'nullable|exists:salesmen,id',
            'collector_id' => [
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
            'allow_credit' => 'nullable|boolean',
            'accept_cheques' => 'nullable|boolean',
            'payment_day' => 'nullable|in:1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25,26,27,28,29,30',
            'track_payment' => 'nullable|in:yes,no',
            'settlement_method' => 'nullable|in:FIFO,Manual',

            // Pricing
            'price_choice' => 'nullable|in:price1,price2,price3,price4,price5,price6,last_invoice_price',
            'price_list' => 'nullable|string|max:255',
            'global_discount' => 'nullable|numeric|min:0|max:100',
            'discount_class' => 'nullable|in:Silver,Gold,Platinum',
            'markup' => 'nullable|numeric|min:0|max:100',
            'markdown' => 'nullable|numeric|min:0|max:100',

            // Tax
            'taxable' => 'nullable|boolean',
            'taxed_from_date' => [
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
            'active' => 'nullable|boolean',
            'black_listed' => 'nullable|boolean',
            'one_time_account' => 'nullable|boolean',
            'special_account' => 'nullable|boolean',
            'pos_customer' => 'nullable|boolean',
            'free_delivery_charge' => 'nullable|boolean',
            'print_invoice_language' => 'nullable|in:English,Arabic',
            'send_invoice' => 'nullable|in:email,sms,whatsapp,all',

            // Message functionality
            'showMessageField' => 'nullable|boolean',
            'message' => 'nullable|string|max:1000',

            // Primary contact
            'contacts_id' => 'nullable|exists:customer_contacts,id',

            // Notes
            'notes' => 'nullable|string',

            // Billing address fields
            'billing_address_line1' => 'nullable|string|max:255',
            'billing_address_line2' => 'nullable|string|max:255',
            'billing_country_id' => 'nullable|exists:countries,id',
            'billing_city_id' => 'nullable|exists:cities,id',
            'billing_district_id' => 'nullable|exists:districts,id',
            'billing_zone_id' => 'nullable|exists:zones,id',
            'billing_building' => 'nullable|string|max:255',
            'billing_block' => 'nullable|string|max:255',
            'billing_floor' => 'nullable|string|max:255',
            'billing_side' => 'nullable|string|max:255',
            'billing_apartment' => 'nullable|string|max:255',
            'billing_zip_code' => 'nullable|string|max:20',

            // Shipping addresses
            'shipping_addresses' => 'nullable|array',
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

            // Credit limits with new structure
            'credit_limits' => 'nullable|array',
            'credit_limits.*' => 'numeric|min:0',

            // Cheque limits with new structure
            'max_cheques' => 'nullable|array',
            'max_cheques.*' => 'integer|min:0',

            // Opening balances with new structure
            'opening_balances' => 'nullable|array',
            'opening_balances.*.currency' => 'required|string|max:10',
            'opening_balances.*.amount' => 'required|numeric',
            'opening_balances.*.date' => 'nullable|date',

            // Contacts validation
            'contacts' => 'nullable|array',
            'contacts.*.title' => 'nullable|string|max:255',
            'contacts.*.name' => 'required|string|max:255',
            'contacts.*.work_phone' => 'nullable|string|max:20',
            'contacts.*.mobile' => 'nullable|string|max:20',
            'contacts.*.position' => 'nullable|string|max:255',
            'contacts.*.extension' => 'nullable|string|max:20',
            'contacts.*.is_primary' => 'boolean',

            // Attachments: support both file uploads and/or metadata
            // If files are uploaded via multipart, validate size/mimes per item
            'attachments' => 'nullable|array',
            'attachments.*' => 'sometimes|file|mimes:jpg,jpeg,png,pdf,docx,xlsx,txt|max:10240',
            // If only metadata is provided (no files), these are optional per item
            'attachments.*.file_name' => 'sometimes|nullable|string|max:255',
            'attachments.*.file_url' => 'sometimes|nullable|url',
            'attachments.*.file_type' => 'sometimes|nullable|string|max:100',
        ];
    }

    public function messages(): array
    {
        return [
            'first_name.required' => 'First name is required.',
            'last_name.required' => 'Last name is required.',

            // Billing address messages
            'billing_country_id.exists' => 'Selected billing country does not exist.',
            'billing_city_id.exists' => 'Selected billing city does not exist.',
            'billing_district_id.exists' => 'Selected billing district does not exist.',
            'billing_zone_id.exists' => 'Selected billing zone does not exist.',

            // Shipping addresses messages
            'shipping_addresses.*.address_line1.required' => 'Address line 1 is required for each shipping address.',
            'shipping_addresses.*.country_id.exists' => 'Selected country does not exist.',
            'shipping_addresses.*.city_id.exists' => 'Selected city does not exist.',
            'shipping_addresses.*.district_id.exists' => 'Selected district does not exist.',
            'shipping_addresses.*.zone_id.exists' => 'Selected zone does not exist.',

            // Credit limits messages
            'credit_limits.*.numeric' => 'Credit limit must be a number.',
            'credit_limits.*.min' => 'Credit limit must be at least 0.',

            // Cheque limits messages
            'max_cheques.*.integer' => 'Maximum cheques must be a whole number.',
            'max_cheques.*.min' => 'Maximum cheques must be at least 0.',

            // Opening balances messages
            'opening_balances.*.currency.required' => 'Currency is required for each opening balance.',
            'opening_balances.*.currency.max' => 'Currency code cannot exceed 10 characters.',
            'opening_balances.*.amount.required' => 'Opening amount is required.',
            'opening_balances.*.amount.numeric' => 'Opening amount must be a number.',
            'opening_balances.*.date.date' => 'Opening date must be a valid date.',

            // Contacts messages
            'contacts.*.name.required' => 'Contact name is required.',
            'contacts.*.name.max' => 'Contact name cannot exceed 255 characters.',
            'contacts.*.work_phone.max' => 'Work phone cannot exceed 20 characters.',
            'contacts.*.mobile.max' => 'Mobile phone cannot exceed 20 characters.',
            'contacts.*.position.max' => 'Position cannot exceed 255 characters.',
            'contacts.*.extension.max' => 'Extension cannot exceed 20 characters.',

            // Attachments messages
            'attachments.*.file_name.required' => 'File name is required.',
            'attachments.*.file_url.required' => 'File URL is required.',
            'attachments.*.file_url.url' => 'File URL must be a valid URL.',
            'attachments.*.file_type.max' => 'File type cannot exceed 100 characters.',
        ];
    }
}
