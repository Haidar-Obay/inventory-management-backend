<?php

namespace App\Http\Requests\Supplier;

use Illuminate\Foundation\Http\FormRequest;

class StoreSupplierRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
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
                // If files are being uploaded via 'attachments' field, exclude attachments from merged data
                // to avoid validation conflict (Laravel will see attachments as file uploads, not array)
                if ($this->hasFile('attachments')) {
                    unset($data['attachments']);
                }
                $this->merge($data);
            }
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Personal Information
            'title' => ['nullable', 'string', 'in:Mr.,Mrs.,Ms.,Dr.,Prof.'],
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'display_name' => ['nullable', 'string', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'phone1' => ['required', 'string', 'max:20', 'unique:suppliers,phone1'],
            'phone2' => ['nullable', 'string', 'max:20', 'unique:suppliers,phone2'],
            'phone3' => ['nullable', 'string', 'max:20', 'unique:suppliers,phone3'],

            // Business Information
            'file_number' => ['nullable', 'string', 'max:255', 'unique:suppliers,file_number'],
            'bar_code' => ['nullable', 'string', 'max:255'],
            'search_terms' => ['nullable', 'array'],
            'search_terms.*' => ['string', 'max:100'],

            // Categorize
            'trade_id' => ['nullable', 'exists:trades,id'],
            'supplier_group_id' => ['nullable', 'exists:supplier_groups,id'],
            'business_type_id' => ['nullable', 'exists:business_types,id'],
            'indicator' => ['nullable', 'string', 'in:A,B,C,D'],

            // Opening balances are handled in separate supplier_opening_balances table

            // Multi-currency opening balances
            'opening_balances' => ['nullable', 'array'],
            'opening_balances.*.currency_id' => ['required', 'exists:currencies,id'],
            'opening_balances.*.opening_amount' => ['required', 'numeric', 'min:0', 'max:999999999.99'],
            'opening_balances.*.opening_date' => ['nullable', 'date'],
            'opening_balances.*.notes' => ['nullable', 'string', 'max:1000'],

            // Payment Terms
            'payment_term_id' => ['nullable', 'exists:payment_terms,id'],
            'payment_method_id' => ['nullable', 'exists:payment_methods,id'],
            'allow_credit' => ['nullable', 'boolean'],
            'payment_day' => ['nullable', 'string', 'in:1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25,26,27,28,29,30'],
            'track_payment' => ['nullable', 'string', 'in:yes,no'],
            'settlement_method' => ['nullable', 'string', 'in:FIFO,Manual'],
            'accept_cheques' => ['nullable', 'boolean'],

            // Credit limits and cheque limits are handled in separate tables for multi-currency support
            'credit_limits' => ['nullable', 'array'],
            'credit_limits.*.currency_id' => ['required', 'exists:currencies,id'],
            'credit_limits.*.credit_limit' => ['required', 'numeric', 'min:0', 'max:999999999.99'],
            'credit_limits.*.notes' => ['nullable', 'string', 'max:1000'],

            'cheque_limits' => ['nullable', 'array'],
            'cheque_limits.*.currency_id' => ['required', 'exists:currencies,id'],
            'cheque_limits.*.max_cheques' => ['nullable', 'integer', 'min:0'],
            'cheque_limits.*.notes' => ['nullable', 'string', 'max:1000'],

            // More Options
            'notes' => ['nullable', 'string'],

            // Taxes
            'taxable' => ['nullable', 'boolean'],
            'taxed_from_date' => ['nullable', 'date'],
            'taxed_till_date' => ['nullable', 'date'],
            'subjected_to_tax' => ['nullable', 'boolean'],
            'added_tax' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'exempted' => ['nullable', 'boolean'],
            'exempted_from' => ['nullable', 'string', 'max:255'],
            'exemption_reference' => ['nullable', 'string', 'max:255'],
            'exempted_from_date' => ['nullable', 'date'],
            'exempted_till_date' => ['nullable', 'date', 'after_or_equal:exempted_from_date'],

            // Catalog
            'catalog' => ['nullable', 'string'],

            // Status flags
            'invoicing_mode' => ['nullable', 'string', 'in:open price,predefined,last price'],
            'is_foreign' => ['nullable', 'boolean'],
            'active' => ['nullable', 'boolean'],
            'message' => ['nullable', 'string'],

            // Addresses
            'billing_address_line1' => ['nullable', 'string', 'max:255'],
            'billing_address_line2' => ['nullable', 'string', 'max:255'],
            'billing_country_id' => ['nullable', 'exists:countries,id'],
            'billing_city_id' => ['nullable', 'exists:cities,id'],
            'billing_district_id' => ['nullable', 'exists:districts,id'],
            'billing_zone_id' => ['nullable', 'exists:zones,id'],
            'billing_building' => ['nullable', 'string', 'max:255'],
            'billing_block' => ['nullable', 'string', 'max:255'],
            'billing_floor' => ['nullable', 'string', 'max:255'],
            'billing_side' => ['nullable', 'string', 'max:255'],
            'billing_apartment' => ['nullable', 'string', 'max:255'],
            'billing_zip_code' => ['nullable', 'string', 'max:20'],
            'billing_address_name' => ['nullable', 'string', 'max:255'],
            'billing_address_notes' => ['nullable', 'string'],

            'shipping_addresses' => ['nullable', 'array'],
            'shipping_addresses.*.address_line1' => ['sometimes', 'nullable', 'string', 'max:255'],
            'shipping_addresses.*.address_line2' => ['nullable', 'string', 'max:255'],
            'shipping_addresses.*.country_id' => ['nullable', 'exists:countries,id'],
            'shipping_addresses.*.city_id' => ['nullable', 'exists:cities,id'],
            'shipping_addresses.*.district_id' => ['nullable', 'exists:districts,id'],
            'shipping_addresses.*.zone_id' => ['nullable', 'exists:zones,id'],
            'shipping_addresses.*.building' => ['nullable', 'string', 'max:255'],
            'shipping_addresses.*.block' => ['nullable', 'string', 'max:255'],
            'shipping_addresses.*.floor' => ['nullable', 'string', 'max:255'],
            'shipping_addresses.*.side' => ['nullable', 'string', 'max:255'],
            'shipping_addresses.*.apartment' => ['nullable', 'string', 'max:255'],
            'shipping_addresses.*.zip_code' => ['nullable', 'string', 'max:20'],
            'shipping_addresses.*.address_name' => ['nullable', 'string', 'max:255'],
            'shipping_addresses.*.notes' => ['nullable', 'string'],
            'shipping_addresses.*.is_primary' => ['nullable', 'boolean'],

            // Contacts
            'contacts' => ['nullable', 'array'],
            'contacts.*.title' => ['nullable', 'string', 'max:255'],
            'contacts.*.name' => ['required', 'string', 'max:255'],
            'contacts.*.work_phone' => ['nullable', 'string', 'max:20'],
            'contacts.*.mobile' => ['nullable', 'string', 'max:20'],
            'contacts.*.position' => ['nullable', 'string', 'max:255'],
            'contacts.*.extension' => ['nullable', 'string', 'max:20'],
            'contacts.*.is_primary' => ['nullable', 'boolean'],

            // Attachments: support both file uploads and/or metadata
            // When files are uploaded, Laravel automatically validates them as files
            // When only metadata is provided, validate as array
            // We exclude attachments from merged data when files are present (in prepareForValidation)
            // So validation only runs on array when no files are present
            'attachments' => ['nullable'],
            'attachments.*' => [
                'sometimes',
                'file',
                function ($attribute, $value, $fail) {
                    if (! ($value instanceof \Illuminate\Http\UploadedFile)) {
                        return;
                    }
                    $allowedMimes = ['image/jpeg', 'image/png', 'application/pdf', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'text/plain'];
                    $allowedExtensions = ['jpg', 'jpeg', 'png', 'pdf', 'docx', 'xlsx', 'txt'];
                    $mime = $value->getMimeType();
                    $extension = strtolower($value->getClientOriginalExtension());
                    if (! in_array($mime, $allowedMimes) && ! in_array($extension, $allowedExtensions)) {
                        $fail('The '.$attribute.' must be a file of type: jpg, jpeg, png, pdf, docx, xlsx, txt.');
                    }
                    if ($value->getSize() > 10240 * 1024) {
                        $fail('The '.$attribute.' must not be larger than 10MB.');
                    }
                },
            ],
            // If only metadata is provided (no files), these are optional per item
            'attachments.*.file_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'attachments.*.file_url' => ['sometimes', 'nullable', 'url'],
            'attachments.*.file_type' => ['sometimes', 'nullable', 'string', 'max:100'],
            'attachments.*.file_path' => ['sometimes', 'nullable', 'string', 'max:500'],
            'attachments.*.file_size' => ['sometimes', 'nullable', 'integer'],
            'attachments.*.description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'attachments.*.category' => ['sometimes', 'nullable', 'string', 'max:255'],
            'attachments.*.is_public' => ['sometimes', 'nullable', 'boolean'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'first_name.required' => 'First name is required.',
            'last_name.required' => 'Last name is required.',
            'phone1.required' => 'Primary phone number is required.',
            'trade_id.exists' => 'Selected trade does not exist.',
            'supplier_group_id.exists' => 'Selected supplier group does not exist.',
            'business_type_id.exists' => 'Selected business type does not exist.',
            'currency_id.exists' => 'Selected currency does not exist.',
            'opening_balances.*.currency_id.required' => 'Currency is required for each opening balance.',
            'opening_balances.*.currency_id.exists' => 'Selected currency does not exist.',
            'opening_balances.*.opening_amount.required' => 'Opening amount is required for each opening balance.',
            'opening_balances.*.opening_amount.numeric' => 'Opening amount must be a number.',
            'opening_balances.*.opening_amount.min' => 'Opening amount must be at least 0.',
            'opening_balances.*.opening_amount.max' => 'Opening amount cannot exceed 999,999,999.99.',
            'opening_balances.*.opening_date.date' => 'Opening date must be a valid date.',
            'payment_term_id.exists' => 'Selected payment term does not exist.',
            'payment_method_id.exists' => 'Selected payment method does not exist.',
            'billing_country_id.exists' => 'Selected billing country does not exist.',
            'billing_city_id.exists' => 'Selected billing city does not exist.',
            'billing_district_id.exists' => 'Selected billing district does not exist.',
            'billing_zone_id.exists' => 'Selected billing zone does not exist.',
            'shipping_addresses.*.country_id.exists' => 'Selected shipping country does not exist.',
            'shipping_addresses.*.city_id.exists' => 'Selected shipping city does not exist.',
            'shipping_addresses.*.district_id.exists' => 'Selected shipping district does not exist.',
            'shipping_addresses.*.zone_id.exists' => 'Selected shipping zone does not exist.',
            'attachments.*.file.max' => 'Attachment file size must not exceed 10MB.',
        ];
    }
}
