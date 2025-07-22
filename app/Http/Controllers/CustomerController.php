<?php

namespace App\Http\Controllers;

use App\Models\{
    Customer,
    Address,
    PaymentTerm
};
use App\Http\Requests\Customer\{
    StoreCustomerRequest,
    UpdateCustomerRequest
};
use App\Models\CustomerAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Imports\DynamicExcelImport;

class CustomerController extends Controller
{
    public function index()
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_customers";

        $customers = app('cache')->store('database')->get($key);

        if (!$customers) {
            $customers = Customer::with([
                'customerGroup:id,name',
                'salesman:id,name',
                'collector:id,name',
                'supervisor:id,name',
                'manager:id,name',
                'paymentTerm:id,code', // changed from name to code
                'paymentMethod:id,code', // changed from name to code
                'trade:id,name',
                'companyCode:id,code',
                'businessType:id,name',
                'salesChannel:id,name',
                'distributionChannel:id,name',
                'mediaChannel:id,name',
                'addresses:id,address_line1,address_line2,country_id,city_id,district_id,zone_id',
                'billingAddresses:id,address_line1,address_line2,country_id,city_id,district_id,zone_id',
                'shippingAddresses:id,address_line1,address_line2,country_id,city_id,district_id,zone_id',
                'primaryBillingAddress:id,address_line1,address_line2,country_id,city_id,district_id,zone_id',
                'primaryShippingAddress:id,address_line1,address_line2,country_id,city_id,district_id,zone_id',
                'primaryContact:id,name,email',
                'contacts:id,name,email',
                'attachments:id,file_name,file_path'
            ])->paginate(10);

            app('cache')->store('database')->forever($key, $customers);
        }

        // Transform the response to be lighter
        $transformedData = $customers->getCollection()->map(function ($customer) {
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
                'file_number' => $customer->file_number,
                'bar_code' => $customer->bar_code,
                'search_terms' => $customer->search_terms,
                'indicator' => $customer->indicator,
                'risk_category' => $customer->risk_category,
                'active' => $customer->active,
                'black_listed' => $customer->black_listed,
                'one_time_account' => $customer->one_time_account,
                'special_account' => $customer->special_account,
                'pos_customer' => $customer->pos_customer,
                'free_delivery_charge' => $customer->free_delivery_charge,
                'print_invoice_language' => $customer->print_invoice_language,
                'send_invoice' => $customer->send_invoice,
                'add_message' => $customer->add_message,
                'invoice_message' => $customer->invoice_message,
                'notes' => $customer->notes,
                'created_at' => $customer->created_at,
                'updated_at' => $customer->updated_at,
                // Related data with only essential info
                'customer_group' => $customer->customerGroup ? [
                    'id' => $customer->customerGroup->id,
                    'name' => $customer->customerGroup->name
                ] : null,
                'salesman' => $customer->salesman ? [
                    'id' => $customer->salesman->id,
                    'name' => $customer->salesman->name
                ] : null,
                'collector' => $customer->collector ? [
                    'id' => $customer->collector->id,
                    'name' => $customer->collector->name
                ] : null,
                'supervisor' => $customer->supervisor ? [
                    'id' => $customer->supervisor->id,
                    'name' => $customer->supervisor->name
                ] : null,
                'manager' => $customer->manager ? [
                    'id' => $customer->manager->id,
                    'name' => $customer->manager->name
                ] : null,
                'payment_term' => $customer->paymentTerm ? [
                    'id' => $customer->paymentTerm->id,
                    'code' => $customer->paymentTerm->code // changed from name to code
                ] : null,
                'payment_method' => $customer->paymentMethod ? [
                    'id' => $customer->paymentMethod->id,
                    'code' => $customer->paymentMethod->code // changed from name to code
                ] : null,
                'trade' => $customer->trade ? [
                    'id' => $customer->trade->id,
                    'name' => $customer->trade->name
                ] : null,
                'company_code' => $customer->companyCode ? [
                    'id' => $customer->companyCode->id,
                    'code' => $customer->companyCode->code
                ] : null,
                'business_type' => $customer->businessType ? [
                    'id' => $customer->businessType->id,
                    'name' => $customer->businessType->name
                ] : null,
                'sales_channel' => $customer->salesChannel ? [
                    'id' => $customer->salesChannel->id,
                    'name' => $customer->salesChannel->name
                ] : null,
                'distribution_channel' => $customer->distributionChannel ? [
                    'id' => $customer->distributionChannel->id,
                    'name' => $customer->distributionChannel->name
                ] : null,
                'media_channel' => $customer->mediaChannel ? [
                    'id' => $customer->mediaChannel->id,
                    'name' => $customer->mediaChannel->name
                ] : null,
                // Addresses with only essential info - use first() to get single model from collection
                'primary_billing_address_id' => $customer->primaryBillingAddress->first() ? $customer->primaryBillingAddress->first()->id : null,
                'primary_shipping_address_id' => $customer->primaryShippingAddress->first() ? $customer->primaryShippingAddress->first()->id : null,
                'primary_contact_id' => $customer->primaryContact ? $customer->primaryContact->id : null,
                // Count of related items
                'addresses_count' => $customer->addresses->count(),
                'contacts_count' => $customer->contacts->count(),
                'attachments_count' => $customer->attachments->count(),
            ];
        });

        // Create new paginator with transformed data
        $transformedPaginator = new \Illuminate\Pagination\LengthAwarePaginator(
            $transformedData,
            $customers->total(),
            $customers->perPage(),
            $customers->currentPage(),
            [
                'path' => $customers->path(),
                'pageName' => $customers->getPageName(),
            ]
        );

        return response()->json([
            'status' => true,
            'message' => 'Customers fetched successfully.',
            'data' => $transformedPaginator,
        ]);
    }

    public function store(StoreCustomerRequest $request)
    {
        $validated = $request->validated();

        // Handle addresses - new structure
        $addresses = [];

        if ($request->has('addresses')) {
            // New structure: array of addresses with types
            foreach ($request->input('addresses') as $addressData) {
                $address = Address::create([
                    'address_line1' => $addressData['address_line1'],
                    'address_line2' => $addressData['address_line2'] ?? null,
                    'country_id' => $addressData['country_id'],
                    'city_id' => $addressData['city_id'],
                    'district_id' => $addressData['district_id'] ?? null,
                    'zone_id' => $addressData['zone_id'] ?? null,
                    'building' => $addressData['building'] ?? null,
                    'block' => $addressData['block'] ?? null,
                    'floor' => $addressData['floor'] ?? null,
                    'side' => $addressData['side'] ?? null,
                    'appartment' => $addressData['appartment'] ?? null,
                    'zip_code' => $addressData['zip_code'] ?? null,
                ]);

                $addresses[] = [
                    'address_id' => $address->id,
                    'address_type' => $addressData['address_type'],
                    'is_primary' => $addressData['is_primary'] ?? false,
                    'address_name' => $addressData['address_name'] ?? null,
                    'notes' => $addressData['notes'] ?? null,
                ];
            }
        } else {
            // Legacy structure: separate billing and shipping addresses
            if ($request->filled('billing_address')) {
                $billingAddress = Address::create($request->input('billing_address'));
                $addresses[] = [
                    'address_id' => $billingAddress->id,
                    'address_type' => 'billing',
                    'is_primary' => true,
                    'address_name' => 'Primary Billing Address',
                ];
            }

            if ($request->filled('shipping_address')) {
                $shippingAddress = Address::create($request->input('shipping_address'));
                $addresses[] = [
                    'address_id' => $shippingAddress->id,
                    'address_type' => 'shipping',
                    'is_primary' => true,
                    'address_name' => 'Primary Shipping Address',
                ];
            }
        }

        // Remove address fields from validated data since we handle them separately
        unset($validated['addresses'], $validated['billing_address'], $validated['shipping_address']);

        if ($request->filled('primary_payment_method_id')) {
            $validated['primary_payment_method_id'] = $request->input('primary_payment_method_id');
        }

        if ($request->filled('payment_term')) {
            $paymentTerm = PaymentTerm::create($request->input('payment_term'));
            $validated['payment_term_id'] = $paymentTerm->id;
        }

        $customer = Customer::create($validated);

        // Attach addresses to customer through pivot table
        foreach ($addresses as $addressData) {
            $customer->addresses()->attach($addressData['address_id'], [
                'address_type' => $addressData['address_type'],
                'is_primary' => $addressData['is_primary'],
                'address_name' => $addressData['address_name'],
                'notes' => $addressData['notes'] ?? null,
            ]);
        }

        // Handle attachments
        if ($request->hasFile('attachments')) {
            $tenantId = tenant('id');
            $files = is_array($request->file('attachments'))
                ? $request->file('attachments')
                : [$request->file('attachments')];

            foreach ($files as $file) {
                $path = Storage::disk('public')->putFile(
                    "tenants/{$tenantId}/{$customer->id}/attachments",
                    $file
                );

                CustomerAttachment::create([
                    'customer_id' => $customer->id,
                    'file_name' => $file->getClientOriginalName(),
                    'file_path' => url(Storage::url($path)),
                    'file_type' => $file->getMimeType(),
                    'file_size' => $file->getSize(),
                    'category' => 'document',
                ]);
            }
        }

        app('cache')->store('database')->forget("tenant_" . tenant('id') . "_customers");

        return response()->json([
            'message' => 'Customer created successfully',
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
            ])->toArray() + [
                'attachments' => $customer->attachments()->pluck('file_path'),
            ],
        ], 201);
    }

    public function show(Customer $customer)
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_customer_show_{$customer->id}";

        $cached = app('cache')->store('database')->get($key);

        if (!$cached) {
            $customer->load([
                'customerGroup:id,name',
                'salesman:id,name',
                'collector:id,name',
                'supervisor:id,name',
                'manager:id,name',
                'paymentTerm:id,code', // changed from name to code
                'paymentMethod:id,code', // changed from name to code
                'trade:id,name',
                'companyCode:id,code',
                'businessType:id,name',
                'salesChannel:id,name',
                'distributionChannel:id,name',
                'mediaChannel:id,name',
                'addresses:id,address_line1,address_line2,country_id,city_id,district_id,zone_id',
                'billingAddresses:id,address_line1,address_line2,country_id,city_id,district_id,zone_id',
                'shippingAddresses:id,address_line1,address_line2,country_id,city_id,district_id,zone_id',
                'primaryBillingAddress:id,address_line1,address_line2,country_id,city_id,district_id,zone_id',
                'primaryShippingAddress:id,address_line1,address_line2,country_id,city_id,district_id,zone_id',
                'primaryContact:id,name,email',
                'contacts:id,name,email',
                'attachments:id,file_name,file_path'
            ]);
            $cached = $customer;
            app('cache')->store('database')->forever($key, $cached);
        }

        // Transform the response to be lighter
        $transformedData = [
            'id' => $cached->id,
            'title' => $cached->title,
            'first_name' => $cached->first_name,
            'middle_name' => $cached->middle_name,
            'last_name' => $cached->last_name,
            'display_name' => $cached->display_name,
            'company_name' => $cached->company_name,
            'phone1' => $cached->phone1,
            'phone2' => $cached->phone2,
            'phone3' => $cached->phone3,
            'file_number' => $cached->file_number,
            'bar_code' => $cached->bar_code,
            'search_terms' => $cached->search_terms,
            'indicator' => $cached->indicator,
            'risk_category' => $cached->risk_category,
            'active' => $cached->active,
            'black_listed' => $cached->black_listed,
            'one_time_account' => $cached->one_time_account,
            'special_account' => $cached->special_account,
            'pos_customer' => $cached->pos_customer,
            'free_delivery_charge' => $cached->free_delivery_charge,
            'print_invoice_language' => $cached->print_invoice_language,
            'send_invoice' => $cached->send_invoice,
            'add_message' => $cached->add_message,
            'invoice_message' => $cached->invoice_message,
            'notes' => $cached->notes,
            'created_at' => $cached->created_at,
            'updated_at' => $cached->updated_at,
            // Related data with only essential info
            'customer_group' => $cached->customerGroup ? [
                'id' => $cached->customerGroup->id,
                'name' => $cached->customerGroup->name
            ] : null,
            'salesman' => $cached->salesman ? [
                'id' => $cached->salesman->id,
                'name' => $cached->salesman->name
            ] : null,
            'collector' => $cached->collector ? [
                'id' => $cached->collector->id,
                'name' => $cached->collector->name
            ] : null,
            'supervisor' => $cached->supervisor ? [
                'id' => $cached->supervisor->id,
                'name' => $cached->supervisor->name
            ] : null,
            'manager' => $cached->manager ? [
                'id' => $cached->manager->id,
                'name' => $cached->manager->name
            ] : null,
            'payment_term' => $cached->paymentTerm ? [
                'id' => $cached->paymentTerm->id,
                'code' => $cached->paymentTerm->code // changed from name to code
            ] : null,
            'payment_method' => $cached->paymentMethod ? [
                'id' => $cached->paymentMethod->id,
                'code' => $cached->paymentMethod->code // changed from name to code
            ] : null,
            'trade' => $cached->trade ? [
                'id' => $cached->trade->id,
                'name' => $cached->trade->name
            ] : null,
            'company_code' => $cached->companyCode ? [
                'id' => $cached->companyCode->id,
                'code' => $cached->companyCode->code
            ] : null,
            'business_type' => $cached->businessType ? [
                'id' => $cached->businessType->id,
                'name' => $cached->businessType->name
            ] : null,
            'sales_channel' => $cached->salesChannel ? [
                'id' => $cached->salesChannel->id,
                'name' => $cached->salesChannel->name
            ] : null,
            'distribution_channel' => $cached->distributionChannel ? [
                'id' => $cached->distributionChannel->id,
                'name' => $cached->distributionChannel->name
            ] : null,
            'media_channel' => $cached->mediaChannel ? [
                'id' => $cached->mediaChannel->id,
                'name' => $cached->mediaChannel->name
            ] : null,
            // Addresses with only essential info - use first() to get single model from collection
            'primary_billing_address_id' => $cached->primaryBillingAddress->first() ? $cached->primaryBillingAddress->first()->id : null,
            'primary_shipping_address_id' => $cached->primaryShippingAddress->first() ? $cached->primaryShippingAddress->first()->id : null,
            'primary_contact_id' => $cached->primaryContact ? $cached->primaryContact->id : null,
            // Count of related items
            'addresses_count' => $cached->addresses->count(),
            'contacts_count' => $cached->contacts->count(),
            'attachments_count' => $cached->attachments->count(),
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

        // Handle addresses - new structure
        if ($request->has('addresses')) {
            // Remove all existing addresses and create new ones
            $customer->addresses()->detach();

            foreach ($request->input('addresses') as $addressData) {
                $address = Address::create([
                    'address_line1' => $addressData['address_line1'],
                    'address_line2' => $addressData['address_line2'] ?? null,
                    'country_id' => $addressData['country_id'],
                    'city_id' => $addressData['city_id'],
                    'district_id' => $addressData['district_id'] ?? null,
                    'zone_id' => $addressData['zone_id'] ?? null,
                    'building' => $addressData['building'] ?? null,
                    'block' => $addressData['block'] ?? null,
                    'floor' => $addressData['floor'] ?? null,
                    'side' => $addressData['side'] ?? null,
                    'appartment' => $addressData['appartment'] ?? null,
                    'zip_code' => $addressData['zip_code'] ?? null,
                ]);

                $customer->addresses()->attach($address->id, [
                    'address_type' => $addressData['address_type'],
                    'is_primary' => $addressData['is_primary'] ?? false,
                    'address_name' => $addressData['address_name'] ?? null,
                    'notes' => $addressData['notes'] ?? null,
                ]);
            }
        } else {
            // Legacy structure: update existing addresses
            if ($request->filled('billing_address')) {
                $primaryBillingAddress = $customer->primaryBillingAddress()->first();
                if ($primaryBillingAddress) {
                    $primaryBillingAddress->update($request->input('billing_address'));
                } else {
                    // Create new billing address if none exists
                    $billingAddress = Address::create($request->input('billing_address'));
                    $customer->addresses()->attach($billingAddress->id, [
                        'address_type' => 'billing',
                        'is_primary' => true,
                        'address_name' => 'Primary Billing Address',
                    ]);
                }
            }

            if ($request->filled('shipping_address')) {
                $primaryShippingAddress = $customer->primaryShippingAddress()->first();
                if ($primaryShippingAddress) {
                    $primaryShippingAddress->update($request->input('shipping_address'));
                } else {
                    // Create new shipping address if none exists
                    $shippingAddress = Address::create($request->input('shipping_address'));
                    $customer->addresses()->attach($shippingAddress->id, [
                        'address_type' => 'shipping',
                        'is_primary' => true,
                        'address_name' => 'Primary Shipping Address',
                    ]);
                }
            }
        }

        // Remove address fields from validated data since we handle them separately
        unset($validated['addresses'], $validated['billing_address'], $validated['shipping_address']);

        if ($request->filled('primary_payment_method_id')) {
            $validated['primary_payment_method_id'] = $request->input('primary_payment_method_id');
        }

        if ($request->filled('payment_term')) {
            if ($customer->paymentTerm) {
                $customer->paymentTerm()->update($request->input('payment_term'));
            } else {
                $paymentTerm = PaymentTerm::create($request->input('payment_term'));
                $validated['payment_term_id'] = $paymentTerm->id;
            }
        }

        $customer->update($validated);

        // Handle attachments
        if ($request->hasFile('attachments')) {
            $tenantId = tenant('id');

            // Delete existing attachments
            foreach ($customer->attachments as $attachment) {
                $relativePath = str_replace(url('/storage'), '', $attachment->file_path);
                Storage::disk('public')->delete($relativePath);
                $attachment->delete();
            }

            // Create new attachments
            $files = is_array($request->file('attachments'))
                ? $request->file('attachments')
                : [$request->file('attachments')];

            foreach ($files as $file) {
                $path = Storage::disk('public')->putFile(
                    "tenants/{$tenantId}/{$customer->id}/attachments",
                    $file
                );

                CustomerAttachment::create([
                    'customer_id' => $customer->id,
                    'file_name' => $file->getClientOriginalName(),
                    'file_path' => url(Storage::url($path)),
                    'file_type' => $file->getMimeType(),
                    'file_size' => $file->getSize(),
                    'category' => 'document',
                ]);
            }
        }

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_customers");
        app('cache')->store('database')->forget("tenant_{$tenantId}_customer_show_{$customer->id}");

        return response()->json([
            'status' => true,
            'message' => 'Customer updated successfully.',
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
            ]),
        ]);
    }

    public function destroy(Customer $customer)
    {
        $customer->delete();

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_customers");
        app('cache')->store('database')->forget("tenant_{$tenantId}_customer_show_{$customer->id}");

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
                $deleted += Customer::where('id', $id)->delete();
                app('cache')->store('database')->forget("tenant_{$tenantId}_customer_show_{$id}");
            } catch (\Illuminate\Database\QueryException $e) {
                $skipped[] = ['id' => $id, 'reason' => $e->getMessage()];
            }
        }

        app('cache')->store('database')->forget("tenant_{$tenantId}_customers");

        return response()->json([
            'message' => 'Bulk delete completed.',
            'deleted_count' => $deleted,
            'skipped' => $skipped,
        ]);
    }

    public function exportExcell()
    {
        $customers = Customer::with([
            'customerGroup',
            'salesman',
            'collector',
            'supervisor',
            'manager',
            'paymentTerm',
            'paymentMethod',
            'trade',
            'companyCode',
            'businessType',
            'salesChannel',
            'distributionChannel',
            'mediaChannel',
        ])->select('id', 'first_name', 'last_name');
        $collection = $customers->get();
        if ($collection->isEmpty()) {
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
            'accept_cheque',
            'payment_day',
            'track_payment',
            'settlement_method',
            'pricing_choice',
            'discount_by_item',
            'global_discount',
            'discount_class',
            'markup_percentage',
            'markdown_percentage',
            'taxable',
            'tax_rate',
            'tax_number',
            'is_exempted',
            'exemption_from',
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
            'add_message',
            'invoice_message',
            'contacts_id',
            'notes'
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
            'Accept Cheque',
            'Payment Day',
            'Track Payment',
            'Settlement Method',
            'Pricing Choice',
            'Discount By Item',
            'Global Discount',
            'Discount Class',
            'Markup Percentage',
            'Markdown Percentage',
            'Taxable',
            'Tax Rate',
            'Tax Number',
            'Is Exempted',
            'Exemption From',
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
            'Notes'
        ];
        return Excel::download(new Export($customers, $columns, $headings), 'customers.xlsx');
    }

    //export pdf
    public function exportPdf(ExportPDF $pdfService)
    {
        $customers = Customer::select(
            'id',
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
            'accept_cheque',
            'payment_day',
            'track_payment',
            'settlement_method',
            'pricing_choice',
            'discount_by_item',
            'global_discount',
            'discount_class',
            'markup_percentage',
            'markdown_percentage',
            'taxable',
            'tax_rate',
            'tax_number',
            'is_exempted',
            'exemption_from',
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
            'add_message',
            'invoice_message',
            'contacts_id',
            'notes'
        )->get();

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
            'accept_cheque' => 'Accept Cheque',
            'payment_day' => 'Payment Day',
            'track_payment' => 'Track Payment',
            'settlement_method' => 'Settlement Method',
            'pricing_choice' => 'Pricing Choice',
            'discount_by_item' => 'Discount By Item',
            'global_discount' => 'Global Discount',
            'discount_class' => 'Discount Class',
            'markup_percentage' => 'Markup Percentage',
            'markdown_percentage' => 'Markdown Percentage',
            'taxable' => 'Taxable',
            'tax_rate' => 'Tax Rate',
            'tax_number' => 'Tax Number',
            'is_exempted' => 'Is Exempted',
            'exemption_from' => 'Exemption From',
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
            'add_message' => 'Add Message',
            'invoice_message' => 'Invoice Message',
            'contacts_id' => 'Contacts ID',
            'notes' => 'Notes'
        ];

        $data = $customers->toArray();

        $pdf = $pdfService->generatePdf($title, $headers, $data);
        return $pdf->download('customers.pdf');
    }

    public function importFromExcel(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv',
        ]);

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
                'accept_cheque',
                'payment_day',
                'track_payment',
                'settlement_method',
                'pricing_choice',
                'discount_by_item',
                'global_discount',
                'discount_class',
                'markup_percentage',
                'markdown_percentage',
                'taxable',
                'tax_rate',
                'tax_number',
                'is_exempted',
                'exemption_from',
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
                'add_message',
                'invoice_message',
                'contacts_id',
                'notes'
            ],
            function ($row) {
                $errors = [];

                // Required fields
                if (empty($row['first_name']))
                    $errors[] = 'Missing first_name';
                if (empty($row['last_name']))
                    $errors[] = 'Missing last_name';

                // Optional validations
                foreach (['phone1', 'phone2', 'phone3'] as $phoneField) {
                    if (!empty($row[$phoneField]) && !is_string($row[$phoneField])) {
                        $errors[] = "$phoneField must be a string";
                    }
                }

                if (isset($row['discount_by_item']) && !is_numeric($row['discount_by_item'])) {
                    $errors[] = 'discount_by_item must be numeric';
                }

                if (isset($row['global_discount']) && !is_numeric($row['global_discount'])) {
                    $errors[] = 'global_discount must be numeric';
                }

                if (isset($row['markup_percentage']) && !is_numeric($row['markup_percentage'])) {
                    $errors[] = 'markup_percentage must be numeric';
                }

                if (isset($row['markdown_percentage']) && !is_numeric($row['markdown_percentage'])) {
                    $errors[] = 'markdown_percentage must be numeric';
                }

                return $errors;
            },
            function ($row) {
                return [
                    'title' => $row['title'] ?? null,
                    'first_name' => $row['first_name'],
                    'middle_name' => $row['middle_name'] ?? null,
                    'last_name' => $row['last_name'],
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
                    'accept_cheque' => boolval($row['accept_cheque'] ?? false),
                    'payment_day' => $row['payment_day'] ?? null,
                    'track_payment' => $row['track_payment'] ?? 'no',
                    'settlement_method' => $row['settlement_method'] ?? 'FIFO',
                    'pricing_choice' => $row['pricing_choice'] ?? 'price1',
                    'discount_by_item' => $row['discount_by_item'] ?? null,
                    'global_discount' => $row['global_discount'] ?? null,
                    'discount_class' => $row['discount_class'] ?? null,
                    'markup_percentage' => $row['markup_percentage'] ?? null,
                    'markdown_percentage' => $row['markdown_percentage'] ?? null,
                    'taxable' => boolval($row['taxable'] ?? false),
                    'tax_rate' => $row['tax_rate'] ?? null,
                    'tax_number' => $row['tax_number'] ?? null,
                    'is_exempted' => boolval($row['is_exempted'] ?? false),
                    'exemption_from' => $row['exemption_from'] ?? null,
                    'exemption_reference' => $row['exemption_reference'] ?? null,
                    'exempted_from_date' => $row['exempted_from_date'] ?? null,
                    'exempted_till_date' => $row['exempted_till_date'] ?? null,
                    'active' => boolval($row['active'] ?? true),
                    'black_listed' => boolval($row['black_listed'] ?? false),
                    'one_time_account' => boolval($row['one_time_account'] ?? true),
                    'special_account' => boolval($row['special_account'] ?? false),
                    'pos_customer' => boolval($row['pos_customer'] ?? false),
                    'free_delivery_charge' => boolval($row['free_delivery_charge'] ?? false),
                    'print_invoice_language' => $row['print_invoice_language'] ?? 'English',
                    'send_invoice' => $row['send_invoice'] ?? 'email',
                    'add_message' => boolval($row['add_message'] ?? false),
                    'invoice_message' => $row['invoice_message'] ?? null,
                    'contacts_id' => $row['contacts_id'] ?? null,
                    'notes' => $row['notes'] ?? null,
                ];
            }
        );

        Excel::import($import, $request->file('file'));

        return response()->json([
            'success' => true,
            'rows_imported' => $import->getImportedCount(),
            'rows_skipped_count' => $import->getSkippedCount(),
            'skipped_rows' => $import->getSkippedRows(),
        ]);
    }

    public function getNames()
    {
        $tenantId = tenant('id');
        $key = "tenant_{$tenantId}_customer_names";

        $customers = app('cache')->store('database')->get($key);

        if (!$customers) {
            $customers = Customer::select('id', 'first_name', 'last_name')
                ->orderBy('first_name')
                ->get()
                ->map(function ($customer) {
                    return [
                        'id' => $customer->id,
                        'name' => trim($customer->first_name . ' ' . $customer->last_name)
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

}

