<?php

namespace App\Http\Requests\Supplier;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class UpdateSupplierRequest extends FormRequest
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
        // Log to see if this method is being called
        Log::info('UpdateSupplierRequest prepareForValidation: Entry', [
            'has_data' => $this->has('data'),
            'input_data' => $this->input('data') !== null ? 'exists' : 'null',
            'hasFile_attachments' => $this->hasFile('attachments'),
            'hasFile_attachments_dot' => $this->hasFile('attachments.*'),
            'content_type' => $this->header('Content-Type'),
            'all_input_keys' => array_keys($this->all()),
            'all_files_keys' => array_keys($this->allFiles()),
        ]);

        // If this is FormData with a 'data' field, decode it and merge into request
        // Try input('data') even if has('data') returns false
        $rawData = $this->input('data');

        // Also try to get from request content if input('data') is null
        // Note: getContent() might return empty if content was already consumed
        // Try to get from the underlying Symfony request
        $content = $this->getContent();
        $symfonyRequest = $this->instance();
        $symfonyContent = $symfonyRequest ? $symfonyRequest->getContent(false) : null;

        Log::info('UpdateSupplierRequest prepareForValidation: Raw content check', [
            'content_exists' => ! empty($content),
            'content_length' => strlen($content),
            'symfony_content_exists' => ! empty($symfonyContent),
            'symfony_content_length' => $symfonyContent ? strlen($symfonyContent) : 0,
            'content_preview' => substr($content ?: ($symfonyContent ?: ''), 0, 500),
            'request_method' => $this->method(),
            'request_uri' => $this->fullUrl(),
        ]);

        // Use symfony content if available and main content is empty
        if (empty($content) && $symfonyContent) {
            $content = $symfonyContent;
        }

        if (! $rawData && $content) {
            // Try to extract data from multipart/form-data
            // Pattern: name="data" followed by Content-Type and then the JSON data
            if (preg_match('/name="data"[\r\n]+Content-Type: [^\r\n]+[\r\n]+[\r\n]+(.*?)(?=------WebKitFormBoundary|$)/s', $content, $matches)) {
                $rawData = trim($matches[1]);
                Log::info('UpdateSupplierRequest prepareForValidation: Found data in raw content', [
                    'data_length' => strlen($rawData),
                    'data_preview' => substr($rawData, 0, 100),
                ]);
            } else {
                // Try simpler pattern - just look for the data field
                if (preg_match('/name="data"[\r\n]+(.*?)(?=------WebKitFormBoundary|$)/s', $content, $matches)) {
                    $rawData = trim($matches[1]);
                    // Remove Content-Type line if present
                    $rawData = preg_replace('/^Content-Type: [^\r\n]+[\r\n]+/m', '', $rawData);
                    Log::info('UpdateSupplierRequest prepareForValidation: Found data with simpler pattern', [
                        'data_length' => strlen($rawData),
                        'data_preview' => substr($rawData, 0, 100),
                    ]);
                }
            }

            // Also check for file fields in the content to confirm they exist
            $fileMatches = preg_match_all('/name="attachments\[\]"/', $content, $fileFieldMatches);
            if ($fileMatches > 0) {
                Log::info('UpdateSupplierRequest prepareForValidation: Found file fields in content', [
                    'file_fields_count' => $fileMatches,
                ]);
            }
        }

        if ($rawData) {
            $data = json_decode($rawData, true);
            if (is_array($data)) {
                Log::info('UpdateSupplierRequest prepareForValidation: Decoded data', [
                    'has_attachments' => isset($data['attachments']),
                    'attachments_count' => isset($data['attachments']) ? count($data['attachments']) : 0,
                ]);

                // Check if files exist in the raw content (Laravel might not have parsed them yet)
                // Look for file fields in the multipart content
                $hasFilesInContent = false;
                if ($content) {
                    // Check for attachments[] or attachments fields in the raw content
                    if (preg_match('/name="attachments\[\]"/', $content) || preg_match('/name="attachments"/', $content)) {
                        $hasFilesInContent = true;
                        Log::info('UpdateSupplierRequest prepareForValidation: Found file fields in raw content');
                    }
                }

                // If files are being uploaded via 'attachments' or 'attachments[]' field,
                // store attachments metadata in a separate field before unsetting it
                $hasFiles = $this->hasFile('attachments') || $this->hasFile('attachments.*') || $hasFilesInContent;
                if ($hasFiles && isset($data['attachments'])) {
                    Log::info('UpdateSupplierRequest prepareForValidation: Storing attachment metadata', [
                        'hasFiles_detected' => $hasFiles,
                        'hasFilesInContent' => $hasFilesInContent,
                    ]);
                    $this->merge(['_attachment_metadata' => $data['attachments']]);
                    unset($data['attachments']);
                }
                $this->merge($data);
            } else {
                Log::warning('UpdateSupplierRequest prepareForValidation: Failed to decode data', [
                    'raw_data_type' => gettype($rawData),
                    'raw_data_length' => is_string($rawData) ? strlen($rawData) : 0,
                ]);
            }
        } else {
            Log::warning('UpdateSupplierRequest prepareForValidation: No data field found');
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $supplierId = $this->route('supplier');

        return [
            // Personal Information
            'title' => ['nullable', 'string', 'in:Mr.,Mrs.,Ms.,Dr.,Prof.'],
            'first_name' => ['sometimes', 'required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['sometimes', 'required', 'string', 'max:255'],
            'display_name' => ['nullable', 'string', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'phone1' => ['sometimes', 'required', 'string', 'max:20', Rule::unique('suppliers', 'phone1')->ignore($supplierId)],
            'phone2' => ['nullable', 'string', 'max:20', Rule::unique('suppliers', 'phone2')->ignore($supplierId)],
            'phone3' => ['nullable', 'string', 'max:20', Rule::unique('suppliers', 'phone3')->ignore($supplierId)],

            // Business Information
            'file_number' => ['nullable', 'string', 'max:255', Rule::unique('suppliers', 'file_number')->ignore($supplierId)],
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
            'opening_balances.*.payment_term_id' => ['nullable', 'exists:payment_terms,id'],
            'opening_balances.*.payment_method_id' => ['nullable', 'exists:payment_methods,id'],
            'opening_balances.*.allow_credit' => ['nullable', 'boolean'],
            'opening_balances.*.payment_day' => ['nullable', 'string', 'in:1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25,26,27,28,29,30'],
            'opening_balances.*.track_payment' => ['nullable', 'string', 'in:yes,no'],
            'opening_balances.*.settlement_method' => ['nullable', 'string', 'in:FIFO,Manual'],

            // Payment Terms
            'payment_term_id' => ['nullable', 'exists:payment_terms,id'],
            'payment_method_id' => ['nullable', 'exists:payment_methods,id'],
            'allow_credit' => ['nullable', 'boolean'],
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

            // Taxes
            'taxable' => ['sometimes', 'nullable', 'boolean'],
            'taxed_from_date' => ['sometimes', 'nullable', 'date'],
            'taxed_till_date' => ['sometimes', 'nullable', 'date'],
            'subjected_to_tax' => ['sometimes', 'nullable', 'boolean'],
            'added_tax' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'exempted' => ['sometimes', 'nullable', 'boolean'],
            'exempted_from' => ['sometimes', 'nullable', 'string', 'max:255'],
            'exemption_reference' => ['sometimes', 'nullable', 'string', 'max:255'],
            'exempted_from_date' => ['sometimes', 'nullable', 'date'],
            'exempted_till_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:exempted_from_date'],

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
            // When only metadata is provided (edit mode with existing attachments), validate as array of objects
            // We exclude attachments from merged data when files are present (in prepareForValidation)
            // So validation only runs on array when no files are present
            'attachments' => ['nullable'],
            // File validation (only applies when files are uploaded)
            // When no files are uploaded, attachments will be array of objects with IDs (existing attachments)
            // Use conditional validation: only validate as file if files are actually being uploaded
            'attachments.*' => [
                function ($attribute, $value, $fail) {
                    // Only validate as file if files are actually being uploaded
                    if ($this->hasFile('attachments') || $this->hasFile('attachments.*')) {
                        // Validate as file
                        if (! ($value instanceof \Illuminate\Http\UploadedFile)) {
                            $fail('The '.$attribute.' must be a file.');
                        }
                        // Validate file type
                        $allowedMimes = ['image/jpeg', 'image/png', 'application/pdf', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'text/plain'];
                        $allowedExtensions = ['jpg', 'jpeg', 'png', 'pdf', 'docx', 'xlsx', 'txt'];
                        if ($value instanceof \Illuminate\Http\UploadedFile) {
                            $mime = $value->getMimeType();
                            $extension = strtolower($value->getClientOriginalExtension());
                            if (! in_array($mime, $allowedMimes) && ! in_array($extension, $allowedExtensions)) {
                                $fail('The '.$attribute.' must be a file of type: jpg, jpeg, png, pdf, docx, xlsx, txt.');
                            }
                            // Validate file size (10MB = 10240 KB)
                            if ($value->getSize() > 10240 * 1024) {
                                $fail('The '.$attribute.' must not be larger than 10MB.');
                            }
                        }
                    }
                    // If no files are being uploaded, allow JSON objects (existing attachments)
                    // No validation needed for JSON objects
                },
            ],
            // Metadata validation (applies when attachments are JSON objects, not files)
            'attachments.*.id' => ['sometimes', 'nullable', 'integer', 'exists:supplier_attachments,id'],
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
