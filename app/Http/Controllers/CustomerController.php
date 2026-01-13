<?php

namespace App\Http\Controllers;

use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Http\Requests\Customer\StoreCustomerRequest;
use App\Http\Requests\Customer\UpdateCustomerRequest;
use App\Imports\DynamicExcelImport;
use App\Models\Address;
use App\Models\Asset;
use App\Models\Customer;
use App\Models\CustomerAttachment;
use App\Models\PaymentTerm;
use App\Models\Project;
use App\Models\Specialist;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class CustomerController extends Controller
{
    public function index()
    {
        // Optimized query - only fetch essential data for grid
        $customers = Customer::select([
            'id',
            'first_name',
            'last_name',
            'phone1',
            'email',
            'active',
            'black_listed',
            'created_at',
            'customer_group_id',
            'salesman_id',
            'payment_term_id',
            'payment_method_id',
        ])->with([
            'customerGroup:id,name',
            'salesman:id,name',
            'paymentTerm:id,name',
            'paymentMethod:id,name',
        ]);

        // Get the customers data
        $customersData = $customers->get();

        // Transform the response to only include essential fields for grid
        $transformedData = $customersData->map(function ($customer) {
            return [
                // Core Identity (Essential)
                'id' => $customer->id,
                'first_name' => $customer->first_name,
                'last_name' => $customer->last_name,
                'phone1' => $customer->phone1,
                'email' => $customer->email,
                'active' => $customer->active,

                // Business Context (Important)
                'customer_group' => $customer->customerGroup ? [
                    'id' => $customer->customerGroup->id,
                    'name' => $customer->customerGroup->name,
                ] : null,
                'salesman' => $customer->salesman ? [
                    'id' => $customer->salesman->id,
                    'name' => $customer->salesman->name,
                ] : null,
                'payment_term' => $customer->paymentTerm ? [
                    'id' => $customer->paymentTerm->id,
                    'name' => $customer->paymentTerm->name,
                ] : null,
                'payment_method' => $customer->paymentMethod ? [
                    'id' => $customer->paymentMethod->id,
                    'name' => $customer->paymentMethod->name,
                ] : null,

                // Status Indicators (Useful)
                'black_listed' => $customer->black_listed,
                'created_at' => $customer->created_at,
            ];
        });

        return response()->json([
            'status' => true,
            'message' => 'Customers fetched successfully.',
            'data' => $transformedData,
        ]);
    }

    public function store(StoreCustomerRequest $request)
    {
        $validated = $request->validated();

        // Use database transaction to ensure all operations succeed or fail together
        return DB::transaction(function () use ($request, $validated) {

            // Remove address fields from validated data since we handle them separately
            unset($validated['billing_address_line1'], $validated['billing_address_line2'],
                $validated['billing_country_id'], $validated['billing_city_id'],
                $validated['billing_district_id'], $validated['billing_zone_id'],
                $validated['billing_building'], $validated['billing_block'],
                $validated['billing_floor'], $validated['billing_side'],
                $validated['billing_apartment'], $validated['billing_zip_code'],
                $validated['shipping_addresses']);

            // Handle payment terms with new field names
            if ($request->filled('selected_payment_term')) {
                $validated['payment_term_id'] = $request->input('selected_payment_term');
            } elseif ($request->filled('payment_term_id')) {
                $validated['payment_term_id'] = $request->input('payment_term_id');
            }

            if ($request->filled('selected_payment_method')) {
                $validated['payment_method_id'] = $request->input('selected_payment_method');
            } elseif ($request->filled('payment_method_id')) {
                $validated['payment_method_id'] = $request->input('payment_method_id');
            }

            // Handle pricing with new field names
            if ($request->filled('price_choice')) {
                $validated['price_choice'] = $request->input('price_choice');
            }

            if ($request->filled('price_list')) {
                $validated['price_list'] = $request->input('price_list');
            }

            if ($request->filled('markup')) {
                $validated['markup_percentage'] = $request->input('markup');
            }

            if ($request->filled('markdown')) {
                $validated['markdown_percentage'] = $request->input('markdown');
            }

            // Handle message field
            if ($request->filled('message')) {
                $validated['message'] = $request->input('message');
            }

            // Convert empty date strings to null for database compatibility
            $dateFields = ['taxed_from_date', 'taxed_till_date', 'exempted_from_date', 'exempted_till_date'];
            foreach ($dateFields as $field) {
                if (isset($validated[$field]) && $validated[$field] === '') {
                    $validated[$field] = null;
                }
            }

            // Create customer FIRST
            $nextId = $this->computeNextAvailableId(Customer::class, 'id');
            $customer = new Customer($validated);
            $customer->id = $nextId;
            $customer->save();

            // Handle billing address - unified structure (after customer is created)
            $hasAnyBillingField = $request->filled('billing_address_line1');
            if (! $hasAnyBillingField) {
                foreach ([
                    'billing_country_id',
                    'billing_city_id',
                    'billing_district_id',
                    'billing_zone_id',
                    'billing_building',
                    'billing_block',
                    'billing_floor',
                    'billing_side',
                    'billing_apartment',
                    'billing_zip_code',
                    'billing_address_line2',
                    'billing_notes',
                ] as $key) {
                    if ($request->has($key)) {
                        $hasAnyBillingField = true;

                        break;
                    }
                }
            }
            if ($hasAnyBillingField) {
                // Create address in addresses table
                $billingAddress = Address::create([
                    'address_line1' => $request->input('billing_address_line1'),
                    'address_line2' => $request->input('billing_address_line2'),
                    'country_id' => $request->input('billing_country_id'),
                    'city_id' => $request->input('billing_city_id'),
                    'district_id' => $request->input('billing_district_id'),
                    'zone_id' => $request->input('billing_zone_id'),
                    'building' => $request->input('billing_building'),
                    'block' => $request->input('billing_block'),
                    'floor' => $request->input('billing_floor'),
                    'side' => $request->input('billing_side'),
                    'appartment' => $request->input('billing_apartment'),
                    'zip_code' => $request->input('billing_zip_code'),
                ]);

                // Attach to customer via pivot table with metadata
                $customer->addresses()->attach($billingAddress->id, [
                    'address_type' => 'billing',
                    'is_primary' => true,
                    'address_name' => 'Primary Billing Address',
                    'notes' => $request->input('billing_notes'),
                ]);
            }

            // Handle shipping addresses - unified structure as array (after customer is created)
            if ($request->has('shipping_addresses')) {
                // First, unset any existing primary shipping addresses to avoid unique constraint violation
                $existingPrimaryShipping = $customer->primaryShippingAddress()->first();
                if ($existingPrimaryShipping) {
                    $customer->addresses()->updateExistingPivot($existingPrimaryShipping->id, ['is_primary' => false]);
                }

                foreach ($request->input('shipping_addresses') as $index => $shippingAddressData) {
                    // Create address in addresses table
                    $shippingAddress = Address::create([
                        'address_line1' => $shippingAddressData['address_line1'],
                        'address_line2' => $shippingAddressData['address_line2'] ?? null,
                        'country_id' => $shippingAddressData['country_id'],
                        'city_id' => $shippingAddressData['city_id'],
                        'district_id' => $shippingAddressData['district_id'] ?? null,
                        'zone_id' => $shippingAddressData['zone_id'] ?? null,
                        'building' => $shippingAddressData['building'] ?? null,
                        'block' => $shippingAddressData['block'] ?? null,
                        'floor' => $shippingAddressData['floor'] ?? null,
                        'side' => $shippingAddressData['side'] ?? null,
                        'appartment' => $shippingAddressData['apartment'] ?? null,
                        'zip_code' => $shippingAddressData['zip_code'] ?? null,
                    ]);

                    // Attach to customer via pivot table with metadata
                    $customer->addresses()->attach($shippingAddress->id, [
                        'address_type' => 'shipping',
                        'is_primary' => $index === 0, // First shipping address is primary
                        'address_name' => $index === 0 ? 'Primary Shipping Address' : 'Shipping Address '.($index + 1),
                        'notes' => $shippingAddressData['notes'] ?? null,
                    ]);
                }
            }

            // Handle opening balances FIRST (required before credit/cheque limits)
            if ($request->has('opening_balances')) {
                foreach ($request->input('opening_balances') as $openingBalanceData) {
                    // Find currency by code
                    $currency = \App\Models\Currency::where('code', $openingBalanceData['currency'])->first();
                    if ($currency) {
                        try {
                            $customer->setOpeningBalance(
                                $currency->id,
                                $openingBalanceData['amount'],
                                $openingBalanceData['date'] ?? null
                            );
                        } catch (\Exception $e) {
                            // Re-throw the exception to trigger transaction rollback
                            throw new \Exception('Opening balance validation failed: '.$e->getMessage());
                        }
                    }
                }
            }

            // Handle credit limits with new structure (after opening balances)
            if ($request->has('credit_limits')) {
                $creditLimits = $request->input('credit_limits');
                foreach ($creditLimits as $currencyCode => $amount) {
                    // Find currency by code
                    $currency = \App\Models\Currency::where('code', $currencyCode)->first();
                    if ($currency) {
                        try {
                            $customer->setCreditLimit($currency->id, $amount);
                        } catch (\Exception $e) {
                            // Re-throw the exception to trigger transaction rollback
                            throw new \Exception('Credit limit validation failed: '.$e->getMessage());
                        }
                    }
                }
            }

            // Handle cheque limits with new structure (after opening balances)
            if ($request->has('max_cheques')) {
                $chequeLimits = $request->input('max_cheques');
                foreach ($chequeLimits as $currencyCode => $maxCheques) {
                    // Find currency by code
                    $currency = \App\Models\Currency::where('code', $currencyCode)->first();
                    if ($currency) {
                        try {
                            $customer->setChequeLimit($currency->id, $maxCheques);
                        } catch (\Exception $e) {
                            // Re-throw the exception to trigger transaction rollback
                            throw new \Exception('Cheque limit validation failed: '.$e->getMessage());
                        }
                    }
                }
            }

            // Handle contacts
            if ($request->has('contacts')) {
                foreach ($request->input('contacts') as $contactData) {
                    $isPrimary = isset($contactData['is_primary']) && (bool) $contactData['is_primary'];

                    $nextContactId = $this->computeNextAvailableId(\App\Models\CustomerContact::class, 'id');
                    $contact = new \App\Models\CustomerContact([
                        'customer_id' => $customer->id,
                        'title' => $contactData['title'] ?? null,
                        'name' => $contactData['name'],
                        'work_phone' => $contactData['work_phone'] ?? null,
                        'mobile' => $contactData['mobile'] ?? null,
                        'email' => $contactData['email'] ?? null,
                        'position' => $contactData['position'] ?? null,
                        'extension' => $contactData['extension'] ?? null,
                        'is_primary' => $isPrimary,
                    ]);
                    $contact->id = $nextContactId;
                    $contact->save();

                    // Set as primary contact if specified (also updates customer.contacts_id)
                    if ($isPrimary) {
                        $customer->setPrimaryContact($contact->id);
                    }
                }
            }

            // Handle associations (many-to-many)
            if ($request->has('associations')) {
                $customer->associations()->sync($request->input('associations'));
            }

            // Handle attachments - check for actual file uploads first
            if ($request->hasFile('attachments')) {
                $tenantId = tenant('id');

                // Handle file uploads
                $files = is_array($request->file('attachments'))
                    ? $request->file('attachments')
                    : [$request->file('attachments')];

                // Get attachment metadata from the decoded data if available
                $attachmentMetadata = [];
                if ($request->has('data')) {
                    $data = json_decode($request->input('data'), true);
                    $attachmentMetadata = $data['attachments'] ?? [];
                }

                foreach ($files as $index => $file) {
                    // Skip if file is null, not valid, or not an instance of UploadedFile
                    if (! $file || ! $file->isValid()) {
                        continue;
                    }

                    $path = Storage::disk('public')->putFile(
                        "tenants/{$tenantId}/customers/{$customer->id}/attachments",
                        $file
                    );

                    // Find matching metadata for this file
                    $metadata = $attachmentMetadata[$index] ?? [];
                    $description = $metadata['description'] ?? '';

                    CustomerAttachment::create([
                        'customer_id' => $customer->id,
                        'file_name' => $file->getClientOriginalName(),
                        'file_path' => url(Storage::url($path)),
                        'file_type' => $file->getMimeType(),
                        'file_size' => $file->getSize(),
                        'description' => $description,
                        'category' => 'document',
                    ]);
                }
            } elseif ($request->has('attachments')) {
                // Handle JSON attachment data (fallback for frontend compatibility)
                // Only process if attachments is an array (not file uploads)
                $attachments = $request->input('attachments');
                if (is_array($attachments)) {
                    foreach ($attachments as $attachmentData) {
                        // Only create attachment if we have a valid file path or file URL
                        $filePath = $attachmentData['file_url'] ?? $attachmentData['file_path'] ?? null;
                        if ($filePath && ! empty(trim($filePath))) {
                            CustomerAttachment::create([
                                'customer_id' => $customer->id,
                                'file_name' => $attachmentData['file_name'] ?? 'Unknown',
                                'file_path' => $filePath,
                                'file_type' => $attachmentData['file_type'] ?? null,
                                'description' => $attachmentData['description'] ?? '',
                                'category' => 'document',
                            ]);
                        }
                    }
                }
            }

            // Clear customer names cache
            $tenantId = tenant('id');
            $cacheKey = "tenant_{$tenantId}_customer_names";
            app('cache')->store('database')->forget($cacheKey);

            return response()->json([
                'status' => true,
                'message' => 'Customer created successfully.',
                'data' => $customer->load([
                    'addresses',
                    'billingAddresses',
                    'shippingAddresses',
                    'primaryBillingAddress',
                    'primaryShippingAddress',
                    'paymentMethod',
                    'paymentTerm',
                    'primaryContact',
                    'contacts',
                    'attachments',
                    'creditLimits.currency',
                    'chequeLimits.currency',
                    'openingBalances.currency',
                ]),
            ]);
        });
    }

    public function show(Customer $customer)
    {
        $customer->load([
            'customerGroup:id,name',
            'salesman:id,name',
            'collector:id,name',
            'supervisor:id,name',
            'manager:id,name',
            'paymentTerm:id,code',
            'paymentMethod:id,code',
            'trade:id,name',
            'companyCode:id,code',
            'businessType:id,name',
            'salesChannel:id,name',
            'distributionChannel:id,name',
            'mediaChannel:id,name',
            'mediaType:id,name',
            'referral:id,name',
            'associations:id,name',
            'addresses:id,address_line1,address_line2,country_id,city_id,district_id,zone_id,building,block,floor,side,appartment,zip_code',
            'billingAddresses:id,address_line1,address_line2,country_id,city_id,district_id,zone_id,building,block,floor,side,appartment,zip_code',
            'shippingAddresses:id,address_line1,address_line2,country_id,city_id,district_id,zone_id,building,block,floor,side,appartment,zip_code',
            'primaryBillingAddress:id,address_line1,address_line2,country_id,city_id,district_id,zone_id,building,block,floor,side,appartment,zip_code',
            'primaryShippingAddress:id,address_line1,address_line2,country_id,city_id,district_id,zone_id,building,block,floor,side,appartment,zip_code',
            'primaryContact:id,name,title,work_phone,mobile,position,extension,is_primary',
            'contacts:id,name,title,work_phone,mobile,position,extension,is_primary',
            'attachments:id,customer_id,file_name,file_path,file_type,file_size,description,category,is_public,created_at,updated_at',
            'creditLimits:id,currency_id,credit_limit,notes,is_active',
            'chequeLimits:id,currency_id,max_cheques,notes,is_active',
            'openingBalances:id,currency_id,opening_amount,opening_date,notes,is_active',
        ]);

        // Refresh contacts relationship to ensure all contacts are loaded
        $customer->load('contacts');

        // Load related currencies for credit limits, cheque limits, and opening balances
        $creditLimits = $customer->activeCreditLimits()->with('currency:id,code,name,iso_code')->get();
        $chequeLimits = $customer->activeChequeLimits()->with('currency:id,code,name,iso_code')->get();
        $openingBalances = $customer->activeOpeningBalances()->with('currency:id,code,name,iso_code')->get();

        // Transform the response to include all customer data
        $transformedData = [
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
            'special_account' => $customer->special_account,
            'pos_customer' => $customer->pos_customer,
            'free_delivery_charge' => $customer->free_delivery_charge,
            'print_invoice_language' => $customer->print_invoice_language,
            'send_invoice' => $customer->send_invoice,
            'message' => $customer->message,
            'notes' => $customer->notes,
            'created_at' => $customer->created_at,
            'updated_at' => $customer->updated_at,

            // Payment and credit related fields
            'allow_credit' => $customer->allow_credit,
            'accept_cheques' => $customer->accept_cheques,
            'payment_day' => $customer->payment_day,
            'track_payment' => $customer->track_payment,
            'settlement_method' => $customer->settlement_method,

            // Pricing related fields
            'price_choice' => $customer->price_choice,
            'price_list' => $customer->price_list,
            'global_discount' => $customer->global_discount,
            'discount_class' => $customer->discount_class,
            'markup_percentage' => $customer->markup_percentage,
            'markdown_percentage' => $customer->markdown_percentage,

            // Tax related fields
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

            // Related data with full info
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
            'payment_term' => $customer->paymentTerm ? [
                'id' => $customer->paymentTerm->id,
                'code' => $customer->paymentTerm->code,
            ] : null,
            'payment_method' => $customer->paymentMethod ? [
                'id' => $customer->paymentMethod->id,
                'code' => $customer->paymentMethod->code,
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

            // New relationships for ClientDrawer
            'media_type' => $customer->mediaType ? [
                'id' => $customer->mediaType->id,
                'name' => $customer->mediaType->name,
            ] : null,
            'referral' => $customer->referral ? [
                'id' => $customer->referral->id,
                'name' => $customer->referral->name,
            ] : null,
            'associations' => $customer->associations->map(function ($association) {
                return [
                    'id' => $association->id,
                    'name' => $association->name,
                ];
            }),
            'status' => $customer->status,

            // Addresses with full details
            'addresses' => $customer->addresses->map(function ($address) {
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
                ];
            }),

            // Billing addresses (sorted: primary first, then others)
            'billing_addresses' => $customer->billingAddresses
                ->sortByDesc(function ($address) {
                    return $address->pivot->is_primary;
                })
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
                    ];
                }),

            // Shipping addresses (sorted: primary first, then others)
            'shipping_addresses' => $customer->shippingAddresses
                ->sortByDesc(function ($address) {
                    return $address->pivot->is_primary;
                })
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
                    ];
                }),

            // Primary addresses (belongsToMany relationships return collection, use first())
            'primary_billing_address' => ($primaryBilling = $customer->primaryBillingAddress()->first()) ? [
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

            'primary_shipping_address' => ($primaryShipping = $customer->primaryShippingAddress()->first()) ? [
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

            // Contacts with full details - get all contacts for this customer
            'contacts' => $customer->contacts()->get()->map(function ($contact) {
                return [
                    'id' => $contact->id,
                    'title' => $contact->title,
                    'name' => $contact->name,
                    'work_phone' => $contact->work_phone,
                    'mobile' => $contact->mobile,
                    'position' => $contact->position,
                    'extension' => $contact->extension,
                    'is_primary' => $contact->is_primary,
                ];
            }),

            // Primary contact
            'primary_contact' => $customer->primaryContact ? [
                'id' => $customer->primaryContact->id,
                'title' => $customer->primaryContact->title,
                'name' => $customer->primaryContact->name,
                'work_phone' => $customer->primaryContact->work_phone,
                'mobile' => $customer->primaryContact->mobile,
                'position' => $customer->primaryContact->position,
                'extension' => $customer->primaryContact->extension,
            ] : null,

            // Attachments with full details
            'attachments' => $customer->attachments->map(function ($attachment) {
                return [
                    'id' => $attachment->id,
                    'file_name' => $attachment->file_name,
                    'file_path' => $attachment->file_path,
                    'file_type' => $attachment->file_type,
                    'file_size' => $attachment->file_size,
                    'category' => $attachment->category,
                ];
            }),

            // Credit limits with currency info
            'credit_limits' => $creditLimits->map(function ($creditLimit) {
                return [
                    'id' => $creditLimit->id,
                    'currency_id' => $creditLimit->currency_id,
                    'currency_code' => $creditLimit->currency->code,
                    'currency_name' => $creditLimit->currency->name,
                    'currency_iso_code' => $creditLimit->currency->iso_code,
                    'credit_limit' => $creditLimit->credit_limit,
                    'notes' => $creditLimit->notes,
                    'is_active' => $creditLimit->is_active,
                ];
            }),

            // Cheque limits with currency info
            'cheque_limits' => $chequeLimits->map(function ($chequeLimit) {
                return [
                    'id' => $chequeLimit->id,
                    'currency_id' => $chequeLimit->currency_id,
                    'currency_code' => $chequeLimit->currency->code,
                    'currency_name' => $chequeLimit->currency->name,
                    'currency_iso_code' => $chequeLimit->currency->iso_code,
                    'max_cheques' => $chequeLimit->max_cheques,
                    'notes' => $chequeLimit->notes,
                    'is_active' => $chequeLimit->is_active,
                ];
            }),

            // Opening balances with currency info
            'opening_balances' => $openingBalances->map(function ($openingBalance) {
                return [
                    'id' => $openingBalance->id,
                    'currency_id' => $openingBalance->currency_id,
                    'currency_code' => $openingBalance->currency->code,
                    'currency_name' => $openingBalance->currency->name,
                    'currency_iso_code' => $openingBalance->currency->iso_code,
                    'opening_amount' => $openingBalance->opening_amount,
                    'opening_date' => $openingBalance->opening_date,
                    'notes' => $openingBalance->notes,
                    'is_active' => $openingBalance->is_active,
                ];
            }),
            // Attachments
            'attachments' => $customer->attachments->map(function ($attachment) {
                return [
                    'id' => $attachment->id,
                    'file_name' => $attachment->file_name,
                    'file_path' => $attachment->file_path,
                    'file_type' => $attachment->file_type,
                    'file_size' => $attachment->file_size,
                    'description' => $attachment->description,
                    'category' => $attachment->category,
                    'is_public' => (bool) $attachment->is_public,
                ];
            }),
        ];

        return response()->json([
            'status' => true,
            'message' => 'Customer details fetched successfully.',
            'data' => $transformedData,
        ]);
    }

    public function update(UpdateCustomerRequest $request, Customer $customer)
    {
        $validated = $request->validated();
        logger()->info('Validated data', $validated);

        // Use database transaction to ensure all operations succeed or fail together
        return DB::transaction(function () use ($request, $validated, $customer) {

            // Handle addresses - unified structure (update existing or create new)
            if ($request->filled('billing_address_line1') || $request->has('shipping_addresses')) {
                // Handle billing address - update existing or create new
                if ($request->filled('billing_address_line1')) {
                    // Get existing primary billing address via pivot
                    $existingBillingPivot = $customer->primaryBillingAddress()->first();

                    $billingAddressData = [
                        'address_line1' => $request->input('billing_address_line1'),
                        'address_line2' => $request->input('billing_address_line2'),
                        'country_id' => $request->input('billing_country_id'),
                        'city_id' => $request->input('billing_city_id'),
                        'district_id' => $request->input('billing_district_id'),
                        'zone_id' => $request->input('billing_zone_id'),
                        'building' => $request->input('billing_building'),
                        'block' => $request->input('billing_block'),
                        'floor' => $request->input('billing_floor'),
                        'side' => $request->input('billing_side'),
                        'appartment' => $request->input('billing_apartment'),
                        'zip_code' => $request->input('billing_zip_code'),
                    ];

                    $billingPivotData = [
                        'address_type' => 'billing',
                        'is_primary' => true,
                        'address_name' => 'Primary Billing Address',
                        'notes' => $request->input('billing_notes'),
                    ];

                    if ($existingBillingPivot) {
                        // UPDATE existing address data
                        $existingBillingPivot->update($billingAddressData);
                        // UPDATE pivot data
                        $customer->addresses()->updateExistingPivot($existingBillingPivot->id, $billingPivotData);
                    } else {
                        // CREATE new address
                        $billingAddress = Address::create($billingAddressData);
                        // Attach to customer via pivot table
                        $customer->addresses()->attach($billingAddress->id, $billingPivotData);
                    }
                } else {
                    // Remove billing address if not provided
                    $billingAddresses = $customer->billingAddresses()->get();
                    foreach ($billingAddresses as $address) {
                        $customer->addresses()->detach($address->id);
                        // Optionally delete the address if not used by others
                        $address->delete();
                    }
                }

                // Handle shipping addresses - update existing or create new
                if ($request->has('shipping_addresses')) {
                    $shippingAddresses = $request->input('shipping_addresses');
                    $existingShippingPivots = $customer->shippingAddresses()->get()->keyBy('id');
                    $newShippingIds = [];

                    // First, unset all existing primary shipping addresses to avoid unique constraint violation
                    $existingPrimaryShipping = $customer->primaryShippingAddress()->first();
                    if ($existingPrimaryShipping) {
                        $customer->addresses()->updateExistingPivot($existingPrimaryShipping->id, ['is_primary' => false]);
                    }

                    foreach ($shippingAddresses as $index => $shippingAddressData) {
                        $shippingAddressDataForTable = [
                            'address_line1' => $shippingAddressData['address_line1'],
                            'address_line2' => $shippingAddressData['address_line2'] ?? null,
                            'country_id' => $shippingAddressData['country_id'],
                            'city_id' => $shippingAddressData['city_id'],
                            'district_id' => $shippingAddressData['district_id'] ?? null,
                            'zone_id' => $shippingAddressData['zone_id'] ?? null,
                            'building' => $shippingAddressData['building'] ?? null,
                            'block' => $shippingAddressData['block'] ?? null,
                            'floor' => $shippingAddressData['floor'] ?? null,
                            'side' => $shippingAddressData['side'] ?? null,
                            'appartment' => $shippingAddressData['apartment'] ?? null,
                            'zip_code' => $shippingAddressData['zip_code'] ?? null,
                        ];

                        $shippingPivotData = [
                            'address_type' => 'shipping',
                            'is_primary' => $index === 0, // First shipping address is primary
                            'address_name' => $index === 0 ? 'Primary Shipping Address' : 'Shipping Address '.($index + 1),
                            'notes' => $shippingAddressData['notes'] ?? null,
                        ];

                        // Check if we should update existing address (by ID if provided)
                        if (isset($shippingAddressData['id']) && $existingShippingPivots->has($shippingAddressData['id'])) {
                            $existingShipping = $existingShippingPivots->get($shippingAddressData['id']);
                            // UPDATE existing address data
                            $existingShipping->update($shippingAddressDataForTable);
                            // UPDATE pivot data
                            $customer->addresses()->updateExistingPivot($existingShipping->id, $shippingPivotData);
                            $newShippingIds[] = $existingShipping->id;
                        } else {
                            // CREATE new address
                            $newAddress = Address::create($shippingAddressDataForTable);
                            // Attach to customer via pivot table
                            $customer->addresses()->attach($newAddress->id, $shippingPivotData);
                            $newShippingIds[] = $newAddress->id;
                        }
                    }

                    // Delete addresses that were removed
                    $addressesToDelete = $existingShippingPivots->keys()->diff($newShippingIds);
                    foreach ($addressesToDelete as $addressId) {
                        $customer->addresses()->detach($addressId);
                        // Optionally delete the address if not used by others
                        $address = Address::find($addressId);
                        if ($address) {
                            $address->delete();
                        }
                    }
                } else {
                    // Remove all shipping addresses if not provided
                    $shippingAddresses = $customer->shippingAddresses()->get();
                    foreach ($shippingAddresses as $address) {
                        $customer->addresses()->detach($address->id);
                        // Optionally delete the address if not used by others
                        $address->delete();
                    }
                }
            }

            // Remove address fields from validated data since we handle them separately
            unset($validated['billing_address_line1'], $validated['billing_address_line2'],
                $validated['billing_country_id'], $validated['billing_city_id'],
                $validated['billing_district_id'], $validated['billing_zone_id'],
                $validated['billing_building'], $validated['billing_block'],
                $validated['billing_floor'], $validated['billing_side'],
                $validated['billing_apartment'], $validated['billing_zip_code'],
                $validated['billing_notes'], $validated['shipping_addresses']);

            // Handle pricing fields mapping (allow null to clear values)
            if ($request->has('markup')) {
                $validated['markup_percentage'] = $request->input('markup');
            }

            if ($request->has('markdown')) {
                $validated['markdown_percentage'] = $request->input('markdown');
            }

            // Handle payment method - support both field name formats
            if ($request->filled('primary_payment_method_id')) {
                $validated['payment_method_id'] = $request->input('primary_payment_method_id');
            } elseif ($request->filled('payment_method_id')) {
                $validated['payment_method_id'] = $request->input('payment_method_id');
            }

            // Handle payment term - support both field name formats
            if ($request->filled('payment_term')) {
                if ($customer->paymentTerm) {
                    $customer->paymentTerm()->update($request->input('payment_term'));
                } else {
                    $paymentTerm = PaymentTerm::create($request->input('payment_term'));
                    $validated['payment_term_id'] = $paymentTerm->id;
                }
            } elseif ($request->filled('payment_term_id')) {
                $validated['payment_term_id'] = $request->input('payment_term_id');
            }

            // Convert empty date strings to null for database compatibility
            $dateFields = ['taxed_from_date', 'taxed_till_date', 'exempted_from_date', 'exempted_till_date'];
            foreach ($dateFields as $field) {
                if (isset($validated[$field]) && $validated[$field] === '') {
                    $validated[$field] = null;
                }
            }

            $customer->update($validated);

            // Handle opening balances FIRST (required before credit/cheque limits)
            if ($request->has('opening_balances')) {
                // Delete existing opening balances completely instead of just marking as inactive
                $customer->openingBalances()->delete();

                foreach ($request->input('opening_balances') as $openingBalanceData) {
                    // Resolve currency ID from either currency_id, numeric currency, or currency code
                    $currencyId = $openingBalanceData['currency_id'] ?? null;
                    if (! $currencyId && isset($openingBalanceData['currency'])) {
                        if (is_numeric($openingBalanceData['currency'])) {
                            $currencyId = (int) $openingBalanceData['currency'];
                        } else {
                            $currency = \App\Models\Currency::where('code', $openingBalanceData['currency'])->first();
                            $currencyId = $currency?->id;
                        }
                    }

                    if ($currencyId) {
                        $amount = $openingBalanceData['opening_amount'] ?? ($openingBalanceData['amount'] ?? null);
                        $date = $openingBalanceData['opening_date'] ?? ($openingBalanceData['date'] ?? null);

                        try {
                            $customer->setOpeningBalance(
                                $currencyId,
                                $amount,
                                $date
                            );
                        } catch (\Exception $e) {
                            // Re-throw the exception to trigger transaction rollback
                            throw new \Exception('Opening balance validation failed: '.$e->getMessage());
                        }
                    }
                }

                // Reload the model and its relationships to ensure opening balances are available for credit limit checks
                $customer->load('openingBalances');
            }

            // Handle credit limits (after opening balances)
            if ($request->has('credit_limits')) {
                // Delete existing credit limits completely instead of just marking as inactive
                $customer->creditLimits()->delete();

                // Get the currencies that have opening balances (from the request data)
                $openingBalanceCurrencies = collect($request->input('opening_balances', []))
                    ->pluck('currency')
                    ->filter()
                    ->toArray();

                foreach ($request->input('credit_limits') as $currencyCode => $amount) {
                    // Find currency by code
                    $currency = \App\Models\Currency::where('code', $currencyCode)->first();
                    if ($currency) {
                        // Check if this currency has an opening balance (from request data, not database)
                        if (in_array($currencyCode, $openingBalanceCurrencies)) {
                            try {
                                // Create credit limit directly instead of using setCreditLimit method
                                $nextCreditId = $this->computeNextAvailableId(\App\Models\CustomerCreditLimit::class, 'id');
                                $customerCredit = new \App\Models\CustomerCreditLimit([
                                    'customer_id' => $customer->id,
                                    'currency_id' => $currency->id,
                                    'credit_limit' => $amount,
                                    'used_credit' => 0,
                                    'available_credit' => $amount,
                                    'notes' => null,
                                    'is_active' => true,
                                ]);
                                $customerCredit->id = $nextCreditId;
                                $customerCredit->save();
                            } catch (\Exception $e) {
                                // Re-throw the exception to trigger transaction rollback
                                throw new \Exception('Credit limit validation failed: '.$e->getMessage());
                            }
                        }
                    }
                }
            }

            // Handle cheque limits (after opening balances)
            if ($request->has('max_cheques')) {
                // Delete existing cheque limits completely instead of just marking as inactive
                $customer->chequeLimits()->delete();

                foreach ($request->input('max_cheques') as $currencyCode => $maxCheques) {
                    // Find currency by code
                    $currency = \App\Models\Currency::where('code', $currencyCode)->first();
                    if ($currency) {
                        try {
                            $customer->setChequeLimit($currency->id, $maxCheques);
                        } catch (\Exception $e) {
                            // Re-throw the exception to trigger transaction rollback
                            throw new \Exception('Cheque limit validation failed: '.$e->getMessage());
                        }
                    }
                }
            }

            // Handle contacts - update existing or create new
            if ($request->has('contacts')) {
                $contacts = $request->input('contacts');
                $existingContacts = $customer->contacts()->get()->keyBy('id');
                $existingContactIds = $existingContacts->keys()->toArray();
                $incomingContactIds = [];

                // Find existing primary contact
                $existingPrimaryContact = $customer->contacts()->where('is_primary', true)->first();

                foreach ($contacts as $contactData) {
                    $isPrimary = isset($contactData['is_primary']) && (bool) $contactData['is_primary'];
                    $contactId = isset($contactData['id']) ? (int) $contactData['id'] : null;

                    // For primary contact without ID, try to find existing primary contact
                    if ($isPrimary && ! $contactId && $existingPrimaryContact) {
                        $contactId = $existingPrimaryContact->id;
                    }

                    // Check if this is an existing contact (has ID and exists in database)
                    if ($contactId && isset($existingContacts[$contactId])) {
                        // Update existing contact
                        $contact = $existingContacts[$contactId];
                        $contact->update([
                            'title' => $contactData['title'] ?? null,
                            'name' => $contactData['name'],
                            'work_phone' => $contactData['work_phone'] ?? null,
                            'mobile' => $contactData['mobile'] ?? null,
                            'email' => $contactData['email'] ?? null,
                            'position' => $contactData['position'] ?? null,
                            'extension' => $contactData['extension'] ?? null,
                            'is_primary' => $isPrimary,
                        ]);

                        // Set as primary contact if specified (also updates customer.contacts_id)
                        if ($isPrimary) {
                            $customer->setPrimaryContact($contact->id);
                        }

                        $incomingContactIds[] = $contactId;
                    } else {
                        // Create new contact
                        $nextContactId = $this->computeNextAvailableId(\App\Models\CustomerContact::class, 'id');
                        $contact = new \App\Models\CustomerContact([
                            'customer_id' => $customer->id,
                            'title' => $contactData['title'] ?? null,
                            'name' => $contactData['name'],
                            'work_phone' => $contactData['work_phone'] ?? null,
                            'mobile' => $contactData['mobile'] ?? null,
                            'email' => $contactData['email'] ?? null,
                            'position' => $contactData['position'] ?? null,
                            'extension' => $contactData['extension'] ?? null,
                            'is_primary' => $isPrimary,
                        ]);
                        $contact->id = $nextContactId;
                        $contact->save();

                        // Set as primary contact if specified (also updates customer.contacts_id)
                        if ($isPrimary) {
                            $customer->setPrimaryContact($contact->id);
                        }

                        $incomingContactIds[] = $contact->id;
                    }
                }

                // Delete contacts that are no longer in the request
                $contactsToDelete = array_diff($existingContactIds, $incomingContactIds);
                if (! empty($contactsToDelete)) {
                    \App\Models\CustomerContact::whereIn('id', $contactsToDelete)
                        ->where('customer_id', $customer->id)
                        ->delete();
                }
            }

            // Handle associations (many-to-many)
            if ($request->has('associations')) {
                $customer->associations()->sync($request->input('associations'));
            }

            // Handle attachments (multipart) - support both 'attachments' and 'attachments[]'
            if ($request->hasFile('attachments') || $request->hasFile('attachments.*')) {
                $tenantId = tenant('id');

                // Get attachment data from JSON (includes existing attachments with IDs + new file metadata)
                // Note: prepareForValidation stores attachments in '_attachment_metadata' before unsetting
                // to avoid validation conflict, so we can access it from there
                $attachmentDataFromJson = [];
                if ($request->has('_attachment_metadata')) {
                    // Get from the stored metadata (set by prepareForValidation)
                    $attachmentDataFromJson = $request->input('_attachment_metadata', []);
                } elseif ($request->has('data')) {
                    // Fallback: try to get from raw data field (if prepareForValidation didn't run)
                    $data = json_decode($request->input('data'), true);
                    $attachmentDataFromJson = $data['attachments'] ?? [];
                }

                // Separate existing attachments (with IDs) from new file metadata (without IDs)
                $existingAttachmentIds = [];
                $attachmentMetadataMap = [];
                $newFileMetadata = [];

                foreach ($attachmentDataFromJson as $attData) {
                    if (isset($attData['id']) && is_numeric($attData['id'])) {
                        // Existing attachment - keep it
                        $existingAttachmentIds[] = $attData['id'];
                        $attachmentMetadataMap[$attData['id']] = $attData;
                    } else {
                        // New file metadata (will be matched with uploaded files)
                        $newFileMetadata[] = $attData;
                    }
                }

                // Delete attachments that are not in the keep list
                $existingAttachments = $customer->attachments;
                foreach ($existingAttachments as $existingAttachment) {
                    if (! in_array($existingAttachment->id, $existingAttachmentIds)) {
                        // Delete file from storage
                        $relativePath = str_replace(url('/storage'), '', $existingAttachment->file_path);
                        Storage::disk('public')->delete($relativePath);
                        // Delete attachment record
                        $existingAttachment->delete();
                    } else {
                        // Update existing attachment metadata if provided
                        $metadata = $attachmentMetadataMap[$existingAttachment->id] ?? null;
                        if ($metadata) {
                            if (isset($metadata['description'])) {
                                $existingAttachment->description = $metadata['description'];
                            }
                            if (isset($metadata['is_public'])) {
                                $existingAttachment->is_public = $metadata['is_public'];
                            }
                            if (isset($metadata['category'])) {
                                $existingAttachment->category = $metadata['category'];
                            }
                            $existingAttachment->save();
                        }
                    }
                }

                // Create new attachments from uploaded files
                // When using attachments[] in FormData, Laravel receives it as attachments.*
                $files = [];
                $fileIdentifiers = []; // Track files by identifier to avoid duplicates

                // Check allFiles() first to get all files, then deduplicate
                $allFiles = $request->allFiles();

                // Collect all files from allFiles() (this is the most reliable source)
                foreach ($allFiles as $key => $file) {
                    if (strpos($key, 'attachment') !== false) {
                        $fileArray = is_array($file) ? $file : [$file];
                        foreach ($fileArray as $f) {
                            if ($f && $f->isValid()) {
                                // Use a combination of name and size as identifier to avoid duplicates
                                $identifier = $f->getClientOriginalName().'|'.$f->getSize().'|'.$f->getMimeType();
                                if (! in_array($identifier, $fileIdentifiers)) {
                                    $files[] = $f;
                                    $fileIdentifiers[] = $identifier;
                                }
                            }
                        }
                    }
                }

                // Fallback: If no files found in allFiles(), try direct methods
                if (count($files) === 0) {
                    // Check for attachments.* first (array notation from FormData)
                    $dot = $request->file('attachments.*');
                    if ($dot) {
                        $dotFiles = is_array($dot) ? $dot : [$dot];
                        foreach ($dotFiles as $file) {
                            if ($file && $file->isValid()) {
                                $identifier = $file->getClientOriginalName().'|'.$file->getSize().'|'.$file->getMimeType();
                                if (! in_array($identifier, $fileIdentifiers)) {
                                    $files[] = $file;
                                    $fileIdentifiers[] = $identifier;
                                }
                            }
                        }
                    }

                    // Also check for direct 'attachments' (single file or already array)
                    $direct = $request->file('attachments');
                    if ($direct) {
                        $directFiles = is_array($direct) ? $direct : [$direct];
                        foreach ($directFiles as $file) {
                            if ($file && $file->isValid()) {
                                $identifier = $file->getClientOriginalName().'|'.$file->getSize().'|'.$file->getMimeType();
                                if (! in_array($identifier, $fileIdentifiers)) {
                                    $files[] = $file;
                                    $fileIdentifiers[] = $identifier;
                                }
                            }
                        }
                    }
                }

                // Match uploaded files with metadata (new files come after existing attachments in the array)
                foreach ($files as $index => $file) {
                    // Skip if file is null, not valid, or not an instance of UploadedFile
                    if (! $file || ! $file->isValid()) {
                        continue;
                    }

                    $path = Storage::disk('public')->putFile(
                        "tenants/{$tenantId}/customers/{$customer->id}/attachments",
                        $file
                    );

                    // Find matching metadata for this file (new files start after existing attachments)
                    $metadata = $newFileMetadata[$index] ?? [];
                    $description = $metadata['description'] ?? '';
                    $category = $metadata['category'] ?? 'document';
                    $isPublic = $metadata['is_public'] ?? true;

                    CustomerAttachment::create([
                        'customer_id' => $customer->id,
                        'file_name' => $file->getClientOriginalName(),
                        'file_path' => url(Storage::url($path)),
                        'file_type' => $file->getMimeType(),
                        'file_size' => $file->getSize(),
                        'description' => $description,
                        'category' => $category,
                        'is_public' => $isPublic,
                    ]);
                }
            }

            // Handle attachments with new structure (JSON data)
            // Only process if no files were uploaded (files are handled above)
            // and if attachments is an array (not file uploads)
            if ($request->has('attachments') && ! $request->hasFile('attachments') && ! $request->hasFile('attachments.*')) {
                $attachments = $request->input('attachments');
                if (is_array($attachments)) {
                    // Get IDs of attachments that should be kept (existing attachments with IDs, not new file uploads)
                    $attachmentIdsToKeep = [];
                    $attachmentMetadataMap = []; // Map of ID => metadata for updates

                    foreach ($attachments as $attachmentData) {
                        // If attachment has an ID, it's an existing attachment that should be kept
                        if (isset($attachmentData['id']) && is_numeric($attachmentData['id'])) {
                            $attachmentIdsToKeep[] = $attachmentData['id'];
                            $attachmentMetadataMap[$attachmentData['id']] = $attachmentData;
                        }
                    }

                    // Delete attachments that are not in the keep list
                    $existingAttachments = $customer->attachments;
                    foreach ($existingAttachments as $existingAttachment) {
                        if (! in_array($existingAttachment->id, $attachmentIdsToKeep)) {
                            // Delete file from storage
                            $relativePath = str_replace(url('/storage'), '', $existingAttachment->file_path);
                            Storage::disk('public')->delete($relativePath);
                            // Delete attachment record
                            $existingAttachment->delete();
                        } else {
                            // Update existing attachment metadata if provided
                            $metadata = $attachmentMetadataMap[$existingAttachment->id] ?? null;
                            if ($metadata) {
                                if (isset($metadata['description'])) {
                                    $existingAttachment->description = $metadata['description'];
                                }
                                if (isset($metadata['is_public'])) {
                                    $existingAttachment->is_public = $metadata['is_public'];
                                }
                                if (isset($metadata['category'])) {
                                    $existingAttachment->category = $metadata['category'];
                                }
                                $existingAttachment->save();
                            }
                        }
                    }

                    // Create new attachments from file URLs (if any)
                    foreach ($attachments as $attachmentData) {
                        // Skip if this is an existing attachment (has ID)
                        if (isset($attachmentData['id']) && is_numeric($attachmentData['id'])) {
                            continue;
                        }

                        // Only create attachment if we have a valid file path or file URL
                        $filePath = $attachmentData['file_url'] ?? $attachmentData['file_path'] ?? null;
                        if ($filePath && ! empty(trim($filePath))) {
                            CustomerAttachment::create([
                                'customer_id' => $customer->id,
                                'file_name' => $attachmentData['file_name'] ?? 'Unknown',
                                'file_path' => $filePath,
                                'file_type' => $attachmentData['file_type'] ?? null,
                                'description' => $attachmentData['description'] ?? '',
                                'category' => 'document',
                            ]);
                        }
                    }
                }
            }

            // Clear customer names cache
            $tenantId = tenant('id');
            $cacheKey = "tenant_{$tenantId}_customer_names";
            app('cache')->store('database')->forget($cacheKey);

            $customer->load([
                'addresses',
                'billingAddresses',
                'shippingAddresses',
                'primaryBillingAddress',
                'primaryShippingAddress',
                'paymentMethod',
                'paymentTerm',
                'primaryContact',
                'contacts',
                'attachments',
                'creditLimits.currency',
                'chequeLimits.currency',
                'openingBalances.currency',
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Customer updated successfully.',
                'data' => $customer,
            ]);
        });
    }

    public function destroy(Customer $customer)
    {
        // Block deletion if the customer has projects; include helpful details
        $projectsCount = Project::where('customer_id', $customer->id)->count();
        if ($projectsCount > 0) {
            $sampleProjectIds = Project::where('customer_id', $customer->id)
                ->select('projects.id')
                ->limit(1)
                ->pluck('id');

            return response()->json([
                'status' => false,
                'message' => 'Cannot delete customer. It is referenced by existing projects.',
                'details' => [
                    'projects' => [
                        'count' => $projectsCount,
                        'sample_ids' => $sampleProjectIds,
                    ],
                ],
            ], 409);
        }

        // Addresses will be automatically deleted via cascade foreign key
        $customer->delete();

        // Remove addresses that became orphaned (not linked to any customer or supplier)
        if (! empty($addressIds)) {
            DB::table('addresses')
                ->whereIn('id', $addressIds)
                ->whereNotExists(function ($q) {
                    $q->select(DB::raw(1))
                        ->from('customer_addresses')
                        ->whereColumn('customer_addresses.address_id', 'addresses.id');
                })
                ->whereNotExists(function ($q) {
                    $q->select(DB::raw(1))
                        ->from('supplier_addresses')
                        ->whereColumn('supplier_addresses.address_id', 'addresses.id');
                })
                ->delete();
        }

        // Clear customer names cache
        $tenantId = tenant('id');
        $cacheKey = "tenant_{$tenantId}_customer_names";
        app('cache')->store('database')->forget($cacheKey);

        return response()->json([
            'status' => true,
            'message' => 'Customer deleted successfully.',
        ]);
    }

    public function bulkDelete(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:customers,id',
        ]);

        $skipped = [];
        $deleted = 0;
        $tenantId = tenant('id');

        foreach ($request->ids as $id) {
            try {
                // Collect address IDs for this customer
                $customer = Customer::with('addresses:id')->find($id);
                $addressIds = $customer ? $customer->addresses()->pluck('addresses.id')->all() : [];

                // Skip if customer has projects and include details
                if ($customer) {
                    $projectsCount = Project::where('customer_id', $customer->id)->count();
                    if ($projectsCount > 0) {
                        $details = [
                            'projects' => [
                                'count' => $projectsCount,
                                'sample_ids' => Project::where('customer_id', $customer->id)
                                    ->select('projects.id')
                                    ->limit(1)
                                    ->pluck('id'),
                            ],
                        ];

                        $skipped[] = [
                            'id' => $id,
                            'reason' => 'Cannot delete customer. It is referenced by existing projects.',
                            'details' => $details,
                        ];

                        continue;
                    }
                }

                $deleted += Customer::where('id', $id)->delete();

                // Cleanup orphaned addresses for this customer
                if (! empty($addressIds)) {
                    DB::table('addresses')
                        ->whereIn('id', $addressIds)
                        ->whereNotExists(function ($q) {
                            $q->select(DB::raw(1))
                                ->from('customer_addresses')
                                ->whereColumn('customer_addresses.address_id', 'addresses.id');
                        })
                        ->whereNotExists(function ($q) {
                            $q->select(DB::raw(1))
                                ->from('supplier_addresses')
                                ->whereColumn('supplier_addresses.address_id', 'addresses.id');
                        })
                        ->delete();
                }
            } catch (\Illuminate\Database\QueryException $e) {
                // Check if it's a foreign key constraint error and include details
                if ($e->getCode() == '23503') {
                    $details = [];

                    try {
                        $customer = Customer::find($id);
                        $projectsCount = $customer ? Project::where('customer_id', $customer->id)->count() : 0;
                        if ($projectsCount > 0) {
                            $details['projects'] = [
                                'count' => $projectsCount,
                                'sample_ids' => Project::where('customer_id', $customer->id)
                                    ->select('projects.id')
                                    ->limit(1)
                                    ->pluck('id'),
                            ];
                        }
                    } catch (\Throwable $ignored) {
                    }

                    $skipped[] = [
                        'id' => $id,
                        'reason' => 'Cannot delete customer. It is referenced by existing projects.',
                        'details' => $details,
                    ];
                } else {
                    $skipped[] = ['id' => $id, 'reason' => $e->getMessage()];
                }
            }
        }

        // Clear customer names cache after bulk delete
        $tenantId = tenant('id');
        $cacheKey = "tenant_{$tenantId}_customer_names";
        app('cache')->store('database')->forget($cacheKey);

        return response()->json([
            'message' => 'Bulk delete completed.',
            'deleted_count' => $deleted,
            'skipped' => $skipped,
        ]);
    }

    public function exportExcell()
    {
        $customers = Customer::query();
        if ((clone $customers)->count() === 0) {
            return response()->json(['message' => 'No customers found.'], 404);
        }
        $columns = [
            'id',
            'first_name',
            'last_name',
            'title',
            'middle_name',
            'company_name',
            'phone1',
            'phone2',
            'phone3',
            'email',
            'card_number',
            'file_number',
            'bar_code',
            'search_terms',
            'trade_id',
            'company_code_id',
            'customer_group_id',
            'business_type_id',
            'sales_channel_id',
            'distribution_channel_id',
            'media_channel_id',
            'indicator',
            'risk_category',
            'salesman_id',
            'collector_id',
            'supervisor_id',
            'manager_id',
            'payment_term_id',
            'payment_method_id',
            'allow_credit',
            'accept_cheques',
            'payment_day',
            'track_payment',
            'settlement_method',
            'price_choice',
            'global_discount',
            'discount_class',
            'markup_percentage',
            'markdown_percentage',
            'taxable',
            'taxed_from_date',
            'taxed_till_date',
            'subjected_to_tax',
            'added_tax',
            'exempted',
            'exempted_from',
            'exemption_reference',
            'exempted_from_date',
            'exempted_till_date',
            'active',
            'black_listed',
            'one_time_account',
            'special_account',
            'pos_customer',
            'free_delivery_charge',
            'print_invoice_language',
            'send_invoice',
            'message',
            'contacts_id',
            'notes',
        ];
        $headings = [
            'ID',
            'First Name',
            'Last Name',
            'Title',
            'Middle Name',
            'Company Name',
            'Phone 1',
            'Phone 2',
            'Phone 3',
            'File Number',
            'Bar Code',
            'Search Terms',
            'Trade ID',
            'Company Code ID',
            'Customer Group ID',
            'Business Type ID',
            'Sales Channel ID',
            'Distribution Channel ID',
            'Media Channel ID',
            'Indicator',
            'Risk Category',
            'Salesman ID',
            'Collector ID',
            'Supervisor ID',
            'Manager ID',
            'Payment Term ID',
            'Payment Method ID',
            'Allow Credit',
            'Accept Cheques',
            'Payment Day',
            'Track Payment',
            'Settlement Method',
            'Price Choice',
            'Global Discount',
            'Discount Class',
            'Markup Percentage',
            'Markdown Percentage',
            'Taxable',
            'Taxed From Date',
            'Taxed Till Date',
            'Subjected To Tax',
            'Added Tax',
            'Is Exempted',
            'Exempted From',
            'Exemption Reference',
            'Exempted From Date',
            'Exempted Till Date',
            'Active',
            'Black Listed',
            'One Time Account',
            'Special Account',
            'POS Customer',
            'Free Delivery Charge',
            'Print Invoice Language',
            'Send Invoice',
            'Add Message',
            'Invoice Message',
            'Contacts ID',
            'Notes',
        ];

        return Excel::download(new Export($customers, $columns, $headings), 'customers.xlsx');
    }

    // export pdf
    public function exportPdf(ExportPDF $pdfService)
    {
        $requestedColumns = request()->input('columns');
        $order = request()->input('order');
        // Size and layout options (orientation, fit, fontSize) are read by ExportPDF from request

        $baseColumns = [
            'id',
            'first_name',
            'last_name',
            'title',
            'middle_name',
            'company_name',
            'phone1',
            'phone2',
            'phone3',
            'email',
            'card_number',
            'file_number',
            'bar_code',
            'search_terms',
            'trade_id',
            'company_code_id',
            'customer_group_id',
            'business_type_id',
            'sales_channel_id',
            'distribution_channel_id',
            'media_channel_id',
            'indicator',
            'risk_category',
            'salesman_id',
            'collector_id',
            'supervisor_id',
            'manager_id',
            'payment_term_id',
            'payment_method_id',
            'allow_credit',
            'accept_cheques',
            'payment_day',
            'track_payment',
            'settlement_method',
            'price_choice',
            'global_discount',
            'discount_class',
            'markup_percentage',
            'markdown_percentage',
            'taxable',
            'taxed_from_date',
            'taxed_till_date',
            'subjected_to_tax',
            'added_tax',
            'exempted',
            'exempted_from',
            'exemption_reference',
            'exempted_from_date',
            'exempted_till_date',
            'active',
            'black_listed',
            'one_time_account',
            'special_account',
            'pos_customer',
            'free_delivery_charge',
            'print_invoice_language',
            'send_invoice',
            'message',
            'contacts_id',
            'notes',
        ];

        $columns = is_array($requestedColumns) && ! empty($requestedColumns)
            ? array_values(array_intersect($requestedColumns, $baseColumns))
            : $baseColumns;

        $query = Customer::select($columns);

        if (is_array($order) && isset($order['by']) && in_array($order['by'], $columns, true)) {
            $direction = strtolower($order['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
            $query->orderBy($order['by'], $direction);
        }

        $customers = $query->get();

        if ($customers->isEmpty()) {
            return response()->json(['message' => 'No customers found.'], 404);
        }

        $title = 'Customer Report';
        $headers = [
            'id' => 'Customer ID',
            'first_name' => 'First Name',
            'last_name' => 'Last Name',
            'title' => 'Title',
            'middle_name' => 'Middle Name',
            'company_name' => 'Company Name',
            'phone1' => 'Phone 1',
            'phone2' => 'Phone 2',
            'phone3' => 'Phone 3',
            'file_number' => 'File Number',
            'bar_code' => 'Bar Code',
            'search_terms' => 'Search Terms',
            'trade_id' => 'Trade ID',
            'company_code_id' => 'Company Code ID',
            'customer_group_id' => 'Customer Group ID',
            'business_type_id' => 'Business Type ID',
            'sales_channel_id' => 'Sales Channel ID',
            'distribution_channel_id' => 'Distribution Channel ID',
            'media_channel_id' => 'Media Channel ID',
            'indicator' => 'Indicator',
            'risk_category' => 'Risk Category',
            'salesman_id' => 'Salesman ID',
            'collector_id' => 'Collector ID',
            'supervisor_id' => 'Supervisor ID',
            'manager_id' => 'Manager ID',
            'payment_term_id' => 'Payment Term ID',
            'payment_method_id' => 'Payment Method ID',
            'allow_credit' => 'Allow Credit',
            'accept_cheques' => 'Accept Cheque',
            'payment_day' => 'Payment Day',
            'track_payment' => 'Track Payment',
            'settlement_method' => 'Settlement Method',
            'price_choice' => 'Price Choice',
            'global_discount' => 'Global Discount',
            'discount_class' => 'Discount Class',
            'markup_percentage' => 'Markup Percentage',
            'markdown_percentage' => 'Markdown Percentage',
            'taxable' => 'Taxable',
            'taxed_from_date' => 'Taxed From Date',
            'taxed_till_date' => 'Taxed Till Date',
            'subjected_to_tax' => 'Subjected To Tax',
            'added_tax' => 'Added Tax',
            'exempted' => 'Is Exempted',
            'exempted_from' => 'Exempted From',
            'exemption_reference' => 'Exemption Reference',
            'exempted_from_date' => 'Exempted From Date',
            'exempted_till_date' => 'Exempted Till Date',
            'active' => 'Active',
            'black_listed' => 'Black Listed',
            'one_time_account' => 'One Time Account',
            'special_account' => 'Special Account',
            'pos_customer' => 'POS Customer',
            'free_delivery_charge' => 'Free Delivery Charge',
            'print_invoice_language' => 'Print Invoice Language',
            'send_invoice' => 'Send Invoice',
            'message' => 'Invoice Message',
            'contacts_id' => 'Contacts ID',
            'notes' => 'Notes',
        ];

        $data = $customers->toArray();

        // Reorder headers to match selected columns
        if (! empty($requestedColumns)) {
            $headers = array_filter($headers, function ($key) use ($columns) {
                return in_array($key, $columns, true);
            }, ARRAY_FILTER_USE_KEY);
            $headers = array_replace(array_flip($columns), $headers);
            foreach ($headers as $key => $val) {
                if ($val === $key) {
                    unset($headers[$key]);
                    $headers[$key] = ucfirst(str_replace('_', ' ', $key));
                }
            }
        }

        $pdf = $pdfService->generatePdf($title, $headers, $data);

        return $pdf->download('customers.pdf');
    }

    public function importFromExcel(Request $request)
    {
        $request->validate([
            'file' => [
                'required',
                'file',
                'mimes:xlsx,xls,csv,txt,text/plain,text/csv,application/csv',
            ],
            'type' => 'nullable|string|in:fresh,mapping',
            'mapping' => 'nullable|array',
        ], [
            'file.mimes' => 'The file field must be a file of type: xlsx, xls, csv',
        ]);

        try {
            // If type is 'fresh', delete all records first so duplicate detection does not skip rows
            if ($request->input('type') === 'fresh') {
                Customer::truncate();
            }

            $import = new DynamicExcelImport(
                Customer::class,
                [
                    'first_name',
                    'last_name',
                    'title',
                    'middle_name',
                    'company_name',
                    'phone1',
                    'phone2',
                    'phone3',
                    'file_number',
                    'bar_code',
                    'search_terms',
                    'trade_id',
                    'company_code_id',
                    'customer_group_id',
                    'business_type_id',
                    'sales_channel_id',
                    'distribution_channel_id',
                    'media_channel_id',
                    'indicator',
                    'risk_category',
                    'salesman_id',
                    'collector_id',
                    'supervisor_id',
                    'manager_id',
                    'payment_term_id',
                    'payment_method_id',
                    'allow_credit',
                    'accept_cheques',
                    'payment_day',
                    'track_payment',
                    'settlement_method',
                    'price_choice',
                    'global_discount',
                    'discount_class',
                    'markup_percentage',
                    'markdown_percentage',
                    'taxable',
                    'taxed_from_date',
                    'taxed_till_date',
                    'subjected_to_tax',
                    'added_tax',
                    'exempted',
                    'exempted_from',
                    'exemption_reference',
                    'exempted_from_date',
                    'exempted_till_date',
                    'active',
                    'black_listed',
                    'one_time_account',
                    'special_account',
                    'pos_customer',
                    'free_delivery_charge',
                    'print_invoice_language',
                    'send_invoice',
                    'showMessageField',
                    'message',
                    'contacts_id',
                    'notes',
                ],
                function ($row) {
                    foreach ($row as $k => $v) {
                        if (is_string($v)) {
                            $row[$k] = trim($v);
                        }
                    }
                    $errors = [];

                    if (($row['first_name'] ?? '') === '') {
                        $errors[] = 'Missing first_name';
                    }
                    if (($row['last_name'] ?? '') === '') {
                        $errors[] = 'Missing last_name';
                    }

                    foreach (['phone1', 'phone2', 'phone3'] as $phoneField) {
                        if (! empty($row[$phoneField]) && ! is_string($row[$phoneField])) {
                            $errors[] = "$phoneField must be a string";
                        }
                    }

                    if (isset($row['global_discount']) && ! is_numeric($row['global_discount'])) {
                        $errors[] = 'global_discount must be numeric';
                    }

                    if (isset($row['markup_percentage']) && ! is_numeric($row['markup_percentage'])) {
                        $errors[] = 'markup_percentage must be numeric';
                    }

                    if (isset($row['markdown_percentage']) && ! is_numeric($row['markdown_percentage'])) {
                        $errors[] = 'markdown_percentage must be numeric';
                    }

                    // Date validation
                    $isValidDate = function ($value) {
                        if ($value === null || $value === '') {
                            return true;
                        }
                        if (is_numeric($value)) {
                            return true;
                        }

                        try {
                            \Carbon\Carbon::createFromFormat('n/j/Y', (string) $value);

                            return true;
                        } catch (\Throwable $e) {
                        }

                        try {
                            \Carbon\Carbon::createFromFormat('m/d/Y', (string) $value);

                            return true;
                        } catch (\Throwable $e) {
                        }

                        try {
                            \Carbon\Carbon::parse((string) $value);

                            return true;
                        } catch (\Throwable $e) {
                        }

                        return false;
                    };
                    foreach (['taxed_from_date', 'taxed_till_date', 'exempted_from_date', 'exempted_till_date', 'exempted_from'] as $df) {
                        if (isset($row[$df]) && $row[$df] !== '' && ! $isValidDate($row[$df])) {
                            $errors[] = "$df has invalid date";
                        }
                    }

                    return $errors;
                },
                function ($row) {
                    foreach ($row as $k => $v) {
                        if (is_string($v)) {
                            $row[$k] = trim($v);
                        }
                    }
                    $parseDate = function ($value) {
                        if ($value === null || $value === '') {
                            return;
                        }
                        if (is_numeric($value)) {
                            try {
                                $dt = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $value);

                                return \Carbon\Carbon::instance($dt)->format('Y-m-d');
                            } catch (\Throwable $e) {
                            }
                        }
                        foreach (['n/j/Y', 'm/d/Y', 'Y-m-d'] as $fmt) {
                            try {
                                return \Carbon\Carbon::createFromFormat($fmt, (string) $value)->format('Y-m-d');
                            } catch (\Throwable $e) {
                            }
                        }

                        try {
                            return \Carbon\Carbon::parse((string) $value)->format('Y-m-d');
                        } catch (\Throwable $e) {
                            return;
                        }
                    };

                    return [
                        'title' => $row['title'] ?? null,
                        'first_name' => $row['first_name'] ?? null,
                        'middle_name' => $row['middle_name'] ?? null,
                        'last_name' => $row['last_name'] ?? null,
                        'display_name' => $row['display_name'] ?? null,
                        'company_name' => $row['company_name'] ?? null,
                        'phone1' => $row['phone1'] ?? null,
                        'phone2' => $row['phone2'] ?? null,
                        'phone3' => $row['phone3'] ?? null,
                        'file_number' => $row['file_number'] ?? null,
                        'bar_code' => $row['bar_code'] ?? null,
                        'search_terms' => $row['search_terms'] ?? null,
                        'trade_id' => $row['trade_id'] ?? null,
                        'company_code_id' => $row['company_code_id'] ?? null,
                        'customer_group_id' => $row['customer_group_id'] ?? null,
                        'business_type_id' => $row['business_type_id'] ?? null,
                        'sales_channel_id' => $row['sales_channel_id'] ?? null,
                        'distribution_channel_id' => $row['distribution_channel_id'] ?? null,
                        'media_channel_id' => $row['media_channel_id'] ?? null,
                        'indicator' => $row['indicator'] ?? null,
                        'risk_category' => $row['risk_category'] ?? null,
                        'salesman_id' => $row['salesman_id'] ?? null,
                        'collector_id' => $row['collector_id'] ?? null,
                        'supervisor_id' => $row['supervisor_id'] ?? null,
                        'manager_id' => $row['manager_id'] ?? null,
                        'payment_term_id' => $row['payment_term_id'] ?? null,
                        'payment_method_id' => $row['payment_method_id'] ?? null,
                        'allow_credit' => boolval($row['allow_credit'] ?? false),
                        'accept_cheques' => boolval($row['accept_cheques'] ?? false),
                        'payment_day' => $row['payment_day'] ?? null,
                        'track_payment' => $row['track_payment'] ?? 'no',
                        'settlement_method' => $row['settlement_method'] ?? null,
                        'price_choice' => $row['price_choice'] ?? null,
                        'global_discount' => $row['global_discount'] ?? null,
                        'discount_class' => $row['discount_class'] ?? null,
                        'markup_percentage' => $row['markup_percentage'] ?? null,
                        'markdown_percentage' => $row['markdown_percentage'] ?? null,
                        'taxable' => boolval($row['taxable'] ?? false),
                        'taxed_from_date' => $parseDate($row['taxed_from_date'] ?? null),
                        'taxed_till_date' => $parseDate($row['taxed_till_date'] ?? null),
                        'subjected_to_tax' => boolval($row['subjected_to_tax'] ?? false),
                        'added_tax' => $row['added_tax'] ?? null,
                        'exempted' => boolval($row['exempted'] ?? false),
                        'exempted_from' => $parseDate($row['exempted_from'] ?? null),
                        'exemption_reference' => $row['exemption_reference'] ?? null,
                        'exempted_from_date' => $parseDate($row['exempted_from_date'] ?? null),
                        'exempted_till_date' => $parseDate($row['exempted_till_date'] ?? null),
                        'active' => boolval($row['active'] ?? true),
                        'black_listed' => boolval($row['black_listed'] ?? false),
                        'one_time_account' => boolval($row['one_time_account'] ?? true),
                        'special_account' => boolval($row['special_account'] ?? false),
                        'pos_customer' => boolval($row['pos_customer'] ?? false),
                        'free_delivery_charge' => boolval($row['free_delivery_charge'] ?? false),
                        'print_invoice_language' => $row['print_invoice_language'] ?? 'English',
                        'send_invoice' => $row['send_invoice'] ?? 'email',
                        'message' => $row['message'] ?? null,
                        'contacts_id' => $row['contacts_id'] ?? null,
                        'notes' => $row['notes'] ?? null,
                    ];
                },
                true, // Enable header validation
                $request->input('type') === 'fresh' // Skip duplicate check when fresh
            );

            Excel::import($import, $request->file('file'));

            // Check if headers were valid
            if (! $import->areHeadersValid()) {
                $headerResult = $import->getHeaderValidationResult();

                return response()->json([
                    'success' => false,
                    'message' => 'Invalid Excel file headers',
                    'header_validation' => $headerResult,
                    'errors' => [
                        'missing_headers' => $headerResult['missing'],
                        'extra_headers' => $headerResult['extra'],
                        'expected_headers' => $headerResult['expected_headers'],
                        'actual_headers' => $headerResult['excel_headers'],
                    ],
                ], 422);
            }

            $imported = $import->getImportedCount();
            $skippedCount = $import->getSkippedCount();
            $skippedRows = $import->getSkippedRows();
            $totalProcessed = $imported + $skippedCount;

            $message = '';
            if ($imported > 0 && $skippedCount === 0) {
                $message = "Imported {$imported} row(s) successfully.";
            } elseif ($imported > 0 && $skippedCount > 0) {
                $message = "Partially imported: {$imported} row(s) added, {$skippedCount} row(s) skipped.";
            } elseif ($imported === 0 && $skippedCount > 0) {
                $message = 'No rows imported. All rows were skipped due to validation errors or duplicates.';
            } else {
                $message = 'No rows found to import.';
            }

            return response()->json([
                'success' => $imported > 0,
                'message' => $message,
                'rows_processed' => $totalProcessed,
                'rows_imported' => $imported,
                'rows_skipped_count' => $skippedCount,
                'skipped_rows' => $skippedRows,
                'header_validation' => $import->getHeaderValidationResult(),
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // Log the error for debugging
            Log::error('Import failed: '.$e->getMessage(), ['exception' => $e]);

            return response()->json([
                'success' => false,
                'message' => 'Import failed due to invalid data. Please check your file for invalid or missing references (e.g., payment method, salesman, etc.).',
                'error_type' => 'database',
            ], 422);
        }
    }

    public function getNames()
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_customer_names";

        $customers = app('cache')->store('database')->get($key);

        if (! $customers) {
            $customers = Customer::select('id', 'first_name', 'middle_name', 'last_name')
                ->orderBy('first_name')
                ->get()
                ->map(function ($customer) {
                    $parts = [
                        $customer->first_name,
                        $customer->middle_name,
                        $customer->last_name,
                    ];

                    return [
                        'id' => $customer->id,
                        'name' => trim(implode(' ', array_filter($parts))),
                    ];
                });

            app('cache')->store('database')->forever($key, $customers);
        }

        return response()->json([
            'status' => true,
            'message' => 'Customer names fetched successfully.',
            'data' => $customers,
        ]);
    }

    /**
     * Search customer by phone number
     */
    public function searchByPhone(Request $request)
    {
        $request->validate([
            'phone' => 'required|string',
        ]);

        $phone = $request->input('phone');

        // Search in phone1, phone2, phone3 fields
        $customer = Customer::where('phone1', $phone)
            ->orWhere('phone2', $phone)
            ->orWhere('phone3', $phone)
            ->first();

        if (! $customer) {
            return response()->json([
                'status' => false,
                'message' => 'Customer not found.',
                'data' => null,
            ], 404);
        }

        // Get primary billing address
        $primaryBillingAddress = $customer->primaryBillingAddress->first();
        $addressLine1 = $primaryBillingAddress ? $primaryBillingAddress->address_line1 : null;

        return response()->json([
            'status' => true,
            'message' => 'Customer found successfully.',
            'data' => [
                'id' => $customer->id,
                'first_name' => $customer->first_name,
                'middle_name' => $customer->middle_name,
                'last_name' => $customer->last_name,
                'date_of_birth' => $customer->date_of_birth,
                'place_of_birth' => $customer->place_of_birth,
                'gender' => $customer->gender,
                'file_number' => $customer->file_number,
                'phone1' => $customer->phone1,
                'phone2' => $customer->phone2,
                'phone3' => $customer->phone3,
                'address_line1' => $addressLine1,
                'black_listed' => $customer->black_listed,
            ],
        ]);
    }

    /**
     * Get customer appointment history
     */
    public function getAppointmentHistory($customerId)
    {
        $customer = Customer::find($customerId);

        if (! $customer) {
            return response()->json([
                'status' => false,
                'message' => 'Customer not found.',
                'data' => [],
            ], 404);
        }

        $appointments = $customer->appointments()
            ->with([
                'services:id,name',
                'visit:id,appointment_id,status,arrived_at,in_progress_at,completed_at,cancelled_at',
            ])
            ->orderBy('start_at', 'desc')
            ->get();

        // Load specialists and assets for services in each appointment
        $appointments->each(function ($appointment) {
            // Get unique specialist and asset IDs from pivot
            $specialistIds = $appointment->services->pluck('pivot.specialist_id')->filter()->unique()->toArray();
            $assetIds = $appointment->services->pluck('pivot.asset_id')->filter()->unique()->toArray();

            // Load specialists and assets
            $specialists = $specialistIds ? Specialist::whereIn('id', $specialistIds)->get()->keyBy('id') : collect();
            $assets = $assetIds ? Asset::whereIn('id', $assetIds)->get()->keyBy('id') : collect();

            // Attach specialists and assets to services
            $appointment->services->each(function ($service) use ($specialists, $assets) {
                $specialistId = $service->pivot->specialist_id ?? null;
                $assetId = $service->pivot->asset_id ?? null;

                if ($specialistId && $specialists->has($specialistId)) {
                    $service->setRelation('specialist', $specialists->get($specialistId));
                }

                if ($assetId && $assets->has($assetId)) {
                    $service->setRelation('asset', $assets->get($assetId));
                }
            });
        });

        return response()->json([
            'status' => true,
            'message' => 'Appointment history fetched successfully.',
            'data' => $appointments,
        ]);
    }

    /**
     * Get customer visit history
     */
    public function getVisitHistory($customerId)
    {
        $customer = Customer::find($customerId);

        if (! $customer) {
            return response()->json([
                'status' => false,
                'message' => 'Customer not found.',
                'data' => [],
            ], 404);
        }

        $visits = $customer->visits()
            ->with([
                'appointment.customers',
                'appointment.services',
                'services', // Load multiple services
            ])
            ->orderBy('arrived_at', 'desc')
            ->get();

        // Load specialists and assets for each visit's appointment and visit services
        foreach ($visits as $visit) {
            if ($visit->appointment) {
                // Get unique specialist and asset IDs from pivot
                $specialistIds = $visit->appointment->services->pluck('pivot.specialist_id')->filter()->unique()->toArray();
                $assetIds = $visit->appointment->services->pluck('pivot.asset_id')->filter()->unique()->toArray();

                // Load specialists and assets
                $specialists = $specialistIds ? \App\Models\Specialist::whereIn('id', $specialistIds)->get()->keyBy('id') : collect();
                $assets = $assetIds ? \App\Models\Asset::whereIn('id', $assetIds)->get()->keyBy('id') : collect();

                // Attach specialists and assets to services
                $visit->appointment->services->each(function ($service) use ($specialists, $assets) {
                    $specialistId = $service->pivot->specialist_id ?? null;
                    $assetId = $service->pivot->asset_id ?? null;

                    if ($specialistId && $specialists->has($specialistId)) {
                        $service->setRelation('specialist', $specialists->get($specialistId));
                    }

                    if ($assetId && $assets->has($assetId)) {
                        $service->setRelation('asset', $assets->get($assetId));
                    }
                });
            }

            // Load specialists for visit services
            if ($visit->services->isNotEmpty()) {
                $specialistIds = $visit->services->pluck('pivot.specialist_id')->filter()->unique()->toArray();
                if (! empty($specialistIds)) {
                    $specialists = \App\Models\Specialist::whereIn('id', $specialistIds)->get()->keyBy('id');
                    $visit->services->each(function ($service) use ($specialists) {
                        $specialistId = $service->pivot->specialist_id ?? null;
                        if ($specialistId && $specialists->has($specialistId)) {
                            $service->setRelation('specialist', $specialists->get($specialistId));
                        }
                    });
                }
            }
        }

        return response()->json([
            'status' => true,
            'message' => 'Visit history fetched successfully.',
            'data' => $visits,
        ]);
    }

    /**
     * Get customer data optimized for invoice creation
     * Returns customer with payment terms, phones, and addresses
     */
    public function getForInvoice($customerId)
    {
        $customer = Customer::with([
            'paymentTerm:id,name,code,nb_days',
            'billingAddresses:id,address_line1,address_line2,city_id,country_id,building,floor,zip_code',
            'shippingAddresses:id,address_line1,address_line2,city_id,country_id,building,floor,zip_code',
            'openingBalances' => function ($query) {
                $query->where('is_active', true)
                    ->with('currency:id,code,name');
            },
        ])->find($customerId);

        if (! $customer) {
            return response()->json([
                'status' => false,
                'message' => 'Customer not found.',
            ], 404);
        }

        // Format phones array (only non-null values)
        $phones = array_filter([
            $customer->phone1,
            $customer->phone2,
            $customer->phone3,
        ]);

        // Format billing addresses
        $billingAddresses = $customer->billingAddresses->map(function ($address) {
            $parts = array_filter([
                $address->address_line1,
                $address->address_line2,
                $address->building ? "Building: {$address->building}" : null,
                $address->floor ? "Floor: {$address->floor}" : null,
                $address->zip_code,
            ]);

            return [
                'id' => $address->id,
                'formatted' => implode(', ', $parts),
                'address_line1' => $address->address_line1,
                'address_line2' => $address->address_line2,
            ];
        });

        // Format shipping addresses
        $shippingAddresses = $customer->shippingAddresses->map(function ($address) {
            $parts = array_filter([
                $address->address_line1,
                $address->address_line2,
                $address->building ? "Building: {$address->building}" : null,
                $address->floor ? "Floor: {$address->floor}" : null,
                $address->zip_code,
            ]);

            return [
                'id' => $address->id,
                'formatted' => implode(', ', $parts),
                'address_line1' => $address->address_line1,
                'address_line2' => $address->address_line2,
            ];
        });

        // Get currencies from active opening balances
        $currencies = $customer->openingBalances->map(function ($openingBalance) {
            return [
                'id' => $openingBalance->currency->id,
                'code' => $openingBalance->currency->code,
                'name' => $openingBalance->currency->name,
            ];
        });

        return response()->json([
            'status' => true,
            'message' => 'Customer data retrieved successfully.',
            'data' => [
                'id' => $customer->id,
                'first_name' => $customer->first_name,
                'middle_name' => $customer->middle_name,
                'last_name' => $customer->last_name,
                'display_name' => $customer->display_name,
                'company_name' => $customer->company_name,
                'phones' => array_values($phones),
                'payment_term' => $customer->paymentTerm ? [
                    'id' => $customer->paymentTerm->id,
                    'name' => $customer->paymentTerm->name,
                    'code' => $customer->paymentTerm->code,
                    'nb_days' => $customer->paymentTerm->nb_days,
                ] : null,
                'billing_addresses' => $billingAddresses,
                'shipping_addresses' => $shippingAddresses,
                'currencies' => $currencies->values(),
            ],
        ]);
    }
}
