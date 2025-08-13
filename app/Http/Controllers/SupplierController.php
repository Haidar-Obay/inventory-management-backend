<?php

namespace App\Http\Controllers;

use App\Models\{
    Supplier,
    Address,
    PaymentTerm,
    PaymentMethod,
    Currency,
    Trade,
    SupplierGroup,
    BusinessType
};
use App\Http\Requests\Supplier\{
    StoreSupplierRequest,
    UpdateSupplierRequest
};
use App\Models\SupplierAttachment;
use App\Services\OpeningBalanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Imports\DynamicExcelImport;

class SupplierController extends Controller
{
    protected $openingBalanceService;

    public function __construct(OpeningBalanceService $openingBalanceService)
    {
        $this->openingBalanceService = $openingBalanceService;
    }

    public function index()
    {
        $suppliers = Supplier::with(['supplierGroup:id,name,code', 'openingBalances.currency:id,code,name']);

        // Get the suppliers data
        $suppliersData = $suppliers->get();

        // Transform the response to include only essential fields
        $transformedData = $suppliersData->map(function ($supplier) {
            return [
                'id' => $supplier->id,
                'title' => $supplier->title,
                'first_name' => $supplier->first_name,
                'middle_name' => $supplier->middle_name,
                'last_name' => $supplier->last_name,
                'display_name' => $supplier->display_name,
                'company_name' => $supplier->company_name,
                'phone1' => $supplier->phone1,
                'phone2' => $supplier->phone2,
                'phone3' => $supplier->phone3,
                'file_number' => $supplier->file_number,
                'barcode' => $supplier->barcode,
                'search_terms' => $supplier->search_terms,
                'indicator' => $supplier->indicator,
                'active' => $supplier->active,
                'notes' => $supplier->notes,
                'is_foreign' => $supplier->is_foreign,
                'supplier_group' => $supplier->supplierGroup ? [
                    'id' => $supplier->supplierGroup->id,
                    'name' => $supplier->supplierGroup->name,
                    'code' => $supplier->supplierGroup->code
                ] : null,
                'opening_balances' => $supplier->openingBalances->map(function ($openingBalance) {
                    return [
                        'id' => $openingBalance->id,
                        'currency_id' => $openingBalance->currency_id,
                        'currency_code' => $openingBalance->currency->code,
                        'currency_name' => $openingBalance->currency->name,
                        'opening_amount' => $openingBalance->opening_amount,
                        'opening_date' => $openingBalance->opening_date,
                        'notes' => $openingBalance->notes,
                        'is_active' => $openingBalance->is_active
                    ];
                }),
                'created_at' => $supplier->created_at,
                'updated_at' => $supplier->updated_at
            ];
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Suppliers retrieved successfully',
            'data' => $transformedData
        ]);
    }

    public function store(StoreSupplierRequest $request)
    {
        try {
            // Create the supplier
            $supplier = Supplier::create($request->validated());

            // Handle addresses
            if ($request->filled('billing_address_line1')) {
                $this->createBillingAddress($supplier, $request);
            }

            if ($request->has('shipping_addresses')) {
                $this->createShippingAddresses($supplier, $request);
            }

            // Handle contacts
            if ($request->has('contacts')) {
                $this->createContacts($supplier, $request);
            }

            // Handle attachments
            if ($request->has('attachments')) {
                $this->createAttachments($supplier, $request);
            }

            // Handle multi-currency opening balances
            if ($request->input('opening_balances')) {
                foreach ($request->input('opening_balances') as $openingBalanceData) {
                    $this->openingBalanceService->setSupplierOpeningBalance(
                        $supplier,
                        $openingBalanceData['currency_id'],
                        $openingBalanceData['opening_amount'],
                        $openingBalanceData['opening_date'] ?? null,
                        $openingBalanceData['notes'] ?? null
                    );
                }
            }

            // Handle multi-currency cheque limits
            if ($request->input('cheque_limits')) {
                foreach ($request->input('cheque_limits') as $chequeLimitData) {
                    $supplier->setChequeLimitForCurrency(
                        $chequeLimitData['currency_id'],
                        $chequeLimitData['max_cheques'],
                        $chequeLimitData['notes'] ?? null
                    );
                }
            }

            // Handle multi-currency credit limits
            if ($request->input('credit_limits')) {
                foreach ($request->input('credit_limits') as $creditLimitData) {
                    $supplier->setCreditLimitForCurrency(
                        $creditLimitData['currency_id'],
                        $creditLimitData['credit_limit'],
                        $creditLimitData['notes'] ?? null
                    );
                }
            }

            // Load relationships for response
            $supplier->load([
                'supplierGroup:id,name',
                'trade:id,name',
                'businessType:id,name',
                'paymentTerm:id,code',
                'paymentMethod:id,code',
                'currency:id,code,name',
                'addresses',
                'contacts',
                'attachments'
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Supplier created successfully',
                'data' => $supplier
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create supplier',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show(Supplier $supplier)
    {
        $supplier->load([
            'supplierGroup:id,name,code,active',
            'trade:id,name,code,active',
            'businessType:id,name,code,active',
            'paymentTerm:id,code,name,active',
            'paymentMethod:id,code,name,active',
            'currency:id,code,name,iso_code,symbol,active',
            'addresses.country:id,name,code,iso_code',
            'addresses.city:id,name,code,country_id',
            'addresses.district:id,name,code,city_id',
            'addresses.zone:id,name,code',
            'billingAddresses.country:id,name,code,iso_code',
            'billingAddresses.city:id,name,code,country_id',
            'billingAddresses.district:id,name,code,city_id',
            'billingAddresses.zone:id,name,code',
            'shippingAddresses.country:id,name,code,iso_code',
            'shippingAddresses.city:id,name,code,country_id',
            'shippingAddresses.district:id,name,code,city_id',
            'shippingAddresses.zone:id,name,code',
            'primaryBillingAddress.country:id,name,code,iso_code',
            'primaryBillingAddress.city:id,name,code,country_id',
            'primaryBillingAddress.district:id,name,code,city_id',
            'primaryBillingAddress.zone:id,name,code',
            'primaryShippingAddress.country:id,name,code,country_id',
            'primaryShippingAddress.city:id,name,code,country_id',
            'primaryShippingAddress.district:id,name,code,city_id',
            'primaryShippingAddress.zone:id,name,code',
            'primaryContact:id,name,title,work_phone,mobile,position,extension,is_primary',
            'contacts:id,name,title,work_phone,mobile,position,extension,is_primary',
            'attachments:id,file_name,file_path,file_type,file_size,description,category,is_public',
            'openingBalances:id,currency_id,opening_amount,opening_date,notes,is_active'
        ]);

        // Transform the response to include all supplier data comprehensively
        $transformedData = [
            'id' => $supplier->id,
            'title' => $supplier->title,
            'first_name' => $supplier->first_name,
            'middle_name' => $supplier->middle_name,
            'last_name' => $supplier->last_name,
            'display_name' => $supplier->display_name,
            'company_name' => $supplier->company_name,
            'phone1' => $supplier->phone1,
            'phone2' => $supplier->phone2,
            'phone3' => $supplier->phone3,
            'file_number' => $supplier->file_number,
            'barcode' => $supplier->barcode,
            'search_terms' => $supplier->search_terms,
            'indicator' => $supplier->indicator,
            'opening_amount' => $supplier->opening_amount,
            'opening_date' => $supplier->opening_date,
            'credit_limit' => $supplier->credit_limit,
            'payment_day' => $supplier->payment_day,
            'track_payment' => $supplier->track_payment,
            'settlement_method' => $supplier->settlement_method,
            'accept_cheques' => $supplier->accept_cheques,
            'max_cheques' => $supplier->max_cheques,
            'taxable' => $supplier->taxable,
            'taxed_from_date' => $supplier->taxed_from_date,
            'taxed_till_date' => $supplier->taxed_till_date,
            'subjected_to_tax' => $supplier->subjected_to_tax,
            'added_tax' => $supplier->added_tax,
            'catalog' => $supplier->catalog,
            'is_foreign' => $supplier->is_foreign,
            'active' => $supplier->active,
            'add_message' => $supplier->add_message,
            'message' => $supplier->message,
            'notes' => $supplier->notes,
            'created_at' => $supplier->created_at,
            'updated_at' => $supplier->updated_at,
            
            // Related data with full info
            'supplier_group' => $supplier->supplierGroup ? [
                'id' => $supplier->supplierGroup->id,
                'name' => $supplier->supplierGroup->name,
                'code' => $supplier->supplierGroup->code,
                'active' => $supplier->supplierGroup->active
            ] : null,
            'trade' => $supplier->trade ? [
                'id' => $supplier->trade->id,
                'name' => $supplier->trade->name,
                'code' => $supplier->trade->code,
                'active' => $supplier->trade->active
            ] : null,
            'business_type' => $supplier->businessType ? [
                'id' => $supplier->businessType->id,
                'name' => $supplier->businessType->name,
                'code' => $supplier->businessType->code,
                'active' => $supplier->businessType->active
            ] : null,
            'payment_term' => $supplier->paymentTerm ? [
                'id' => $supplier->paymentTerm->id,
                'code' => $supplier->paymentTerm->code,
                'name' => $supplier->paymentTerm->name,
                'active' => $supplier->paymentTerm->active
            ] : null,
            'payment_method' => $supplier->paymentMethod ? [
                'id' => $supplier->paymentMethod->id,
                'code' => $supplier->paymentMethod->code,
                'name' => $supplier->paymentMethod->name,
                'active' => $supplier->paymentMethod->active
            ] : null,
            'currency' => $supplier->currency ? [
                'id' => $supplier->currency->id,
                'code' => $supplier->currency->code,
                'name' => $supplier->currency->name,
                'iso_code' => $supplier->currency->iso_code,
                'symbol' => $supplier->currency->symbol,
                'active' => $supplier->currency->active
            ] : null,
            
            // Addresses with full details including location hierarchy
            'addresses' => $supplier->addresses->map(function ($address) {
                return [
                    'id' => $address->id,
                    'address_line1' => $address->address_line1,
                    'address_line2' => $address->address_line2,
                    'country_id' => $address->country_id,
                    'city_id' => $address->city_id,
                    'district_id' => $address->district_id,
                    'zone_id' => $address->zone_id,
                    'country' => $address->country ? [
                        'id' => $address->country->id,
                        'name' => $address->country->name,
                        'code' => $address->country->code,
                        'iso_code' => $address->country->iso_code
                    ] : null,
                    'city' => $address->city ? [
                        'id' => $address->city->id,
                        'name' => $address->city->name,
                        'code' => $address->city->code,
                        'country_id' => $address->city->country_id
                    ] : null,
                    'district' => $address->district ? [
                        'id' => $address->district->id,
                        'name' => $address->district->name,
                        'code' => $address->district->code,
                        'city_id' => $address->district->city_id
                    ] : null,
                    'zone' => $address->zone ? [
                        'id' => $address->zone->id,
                        'name' => $address->zone->name,
                        'code' => $address->zone->code
                    ] : null,
                    'pivot' => $address->pivot
                ];
            }),
            
            // Billing addresses with full details
            'billing_addresses' => $supplier->billingAddresses->map(function ($address) {
                return [
                    'id' => $address->id,
                    'address_line1' => $address->address_line1,
                    'address_line2' => $address->address_line2,
                    'country_id' => $address->country_id,
                    'city_id' => $address->city_id,
                    'district_id' => $address->district_id,
                    'zone_id' => $address->zone_id,
                    'country' => $address->country ? [
                        'id' => $address->country->id,
                        'name' => $address->country->name,
                        'code' => $address->country->code,
                        'iso_code' => $address->country->iso_code
                    ] : null,
                    'city' => $address->city ? [
                        'id' => $address->city->id,
                        'name' => $address->city->name,
                        'code' => $address->city->code,
                        'country_id' => $address->city->country_id
                    ] : null,
                    'district' => $address->district ? [
                        'id' => $address->district->id,
                        'name' => $address->district->name,
                        'code' => $address->district->code,
                        'city_id' => $address->district->city_id
                    ] : null,
                    'zone' => $address->zone ? [
                        'id' => $address->zone->id,
                        'name' => $address->zone->name,
                        'code' => $address->zone->code
                    ] : null,
                    'pivot' => $address->pivot
                ];
            }),
            
            // Shipping addresses with full details
            'shipping_addresses' => $supplier->shippingAddresses->map(function ($address) {
                return [
                    'id' => $address->id,
                    'address_line1' => $address->address_line1,
                    'address_line2' => $address->address_line2,
                    'country_id' => $address->country_id,
                    'city_id' => $address->city_id,
                    'district_id' => $address->district_id,
                    'zone_id' => $address->zone_id,
                    'country' => $address->country ? [
                        'id' => $address->country->id,
                        'name' => $address->country->name,
                        'code' => $address->country->code,
                        'iso_code' => $address->country->iso_code
                    ] : null,
                    'city' => $address->city ? [
                        'id' => $address->city->id,
                        'name' => $address->city->name,
                        'code' => $address->city->code,
                        'country_id' => $address->city->country_id
                    ] : null,
                    'district' => $address->district ? [
                        'id' => $address->district->id,
                        'name' => $address->district->name,
                        'code' => $address->district->code,
                        'city_id' => $address->district->city_id
                    ] : null,
                    'zone' => $address->zone ? [
                        'id' => $address->zone->id,
                        'name' => $address->zone->name,
                        'code' => $address->zone->code
                    ] : null,
                    'pivot' => $address->pivot
                ];
            }),
            
            // Primary billing address with full details
            'primary_billing_address' => $supplier->primaryBillingAddress->first() ? [
                'id' => $supplier->primaryBillingAddress->first()->id,
                'address_line1' => $supplier->primaryBillingAddress->first()->address_line1,
                'address_line2' => $supplier->primaryBillingAddress->first()->address_line2,
                'country_id' => $supplier->primaryBillingAddress->first()->country_id,
                'city_id' => $supplier->primaryBillingAddress->first()->city_id,
                'district_id' => $supplier->primaryBillingAddress->first()->district_id,
                'zone_id' => $supplier->primaryBillingAddress->first()->zone_id,
                'country' => $supplier->primaryBillingAddress->first()->country ? [
                    'id' => $supplier->primaryBillingAddress->first()->country->id,
                    'name' => $supplier->primaryBillingAddress->first()->country->name,
                    'code' => $supplier->primaryBillingAddress->first()->country->code,
                    'iso_code' => $supplier->primaryBillingAddress->first()->country->iso_code
                ] : null,
                'city' => $supplier->primaryBillingAddress->first()->city ? [
                    'id' => $supplier->primaryBillingAddress->first()->city->id,
                    'name' => $supplier->primaryBillingAddress->first()->city->name,
                    'code' => $supplier->primaryBillingAddress->first()->city->code,
                    'country_id' => $supplier->primaryBillingAddress->first()->city->country_id
                ] : null,
                'district' => $supplier->primaryBillingAddress->first()->district ? [
                    'id' => $supplier->primaryBillingAddress->first()->district->id,
                    'name' => $supplier->primaryBillingAddress->first()->district->name,
                    'code' => $supplier->primaryBillingAddress->first()->district->code,
                    'city_id' => $supplier->primaryBillingAddress->first()->district->city_id
                ] : null,
                'zone' => $supplier->primaryBillingAddress->first()->zone ? [
                    'id' => $supplier->primaryBillingAddress->first()->zone->id,
                    'name' => $supplier->primaryBillingAddress->first()->zone->name,
                    'code' => $supplier->primaryBillingAddress->first()->zone->code
                ] : null,
                'pivot' => $supplier->primaryBillingAddress->first()->pivot
            ] : null,
            
            // Primary shipping address with full details
            'primary_shipping_address' => $supplier->primaryShippingAddress->first() ? [
                'id' => $supplier->primaryShippingAddress->first()->id,
                'address_line1' => $supplier->primaryShippingAddress->first()->address_line1,
                'address_line2' => $supplier->primaryShippingAddress->first()->address_line2,
                'country_id' => $supplier->primaryShippingAddress->first()->country_id,
                'city_id' => $supplier->primaryShippingAddress->first()->city_id,
                'district_id' => $supplier->primaryShippingAddress->first()->district_id,
                'zone_id' => $supplier->primaryShippingAddress->first()->zone_id,
                'country' => $supplier->primaryShippingAddress->first()->country ? [
                    'id' => $supplier->primaryShippingAddress->first()->country->id,
                    'name' => $supplier->primaryShippingAddress->first()->country->name,
                    'code' => $supplier->primaryShippingAddress->first()->country->code,
                    'iso_code' => $supplier->primaryShippingAddress->first()->country->iso_code
                ] : null,
                'city' => $supplier->primaryShippingAddress->first()->city ? [
                    'id' => $supplier->primaryShippingAddress->first()->city->id,
                    'name' => $supplier->primaryShippingAddress->first()->city->name,
                    'code' => $supplier->primaryShippingAddress->first()->city->code,
                    'country_id' => $supplier->primaryShippingAddress->first()->city->country_id
                ] : null,
                'district' => $supplier->primaryShippingAddress->first()->district ? [
                    'id' => $supplier->primaryShippingAddress->first()->district->id,
                    'name' => $supplier->primaryShippingAddress->first()->district->name,
                    'code' => $supplier->primaryShippingAddress->first()->district->code,
                    'city_id' => $supplier->primaryShippingAddress->first()->district->city_id
                ] : null,
                'zone' => $supplier->primaryShippingAddress->first()->zone ? [
                    'id' => $supplier->primaryShippingAddress->first()->zone->id,
                    'name' => $supplier->primaryShippingAddress->first()->zone->name,
                    'code' => $supplier->primaryShippingAddress->first()->zone->code
                ] : null,
                'pivot' => $supplier->primaryShippingAddress->first()->pivot
            ] : null,
            
            // Primary contact with full details
            'primary_contact' => $supplier->primaryContact ? [
                'id' => $supplier->primaryContact->id,
                'name' => $supplier->primaryContact->name,
                'title' => $supplier->primaryContact->title,
                'work_phone' => $supplier->primaryContact->work_phone,
                'mobile' => $supplier->primaryContact->mobile,
                'position' => $supplier->primaryContact->position,
                'extension' => $supplier->primaryContact->extension,
                'is_primary' => $supplier->primaryContact->is_primary
            ] : null,
            
            // All contacts with full details
            'contacts' => $supplier->contacts->map(function ($contact) {
                return [
                    'id' => $contact->id,
                    'name' => $contact->name,
                    'title' => $contact->title,
                    'work_phone' => $contact->work_phone,
                    'mobile' => $contact->mobile,
                    'position' => $contact->position,
                    'extension' => $contact->extension,
                    'is_primary' => $contact->is_primary
                ];
            }),
            
            // Attachments with full details
            'attachments' => $supplier->attachments->map(function ($attachment) {
                return [
                    'id' => $attachment->id,
                    'file_name' => $attachment->file_name,
                    'file_path' => $attachment->file_path,
                    'file_type' => $attachment->file_type,
                    'file_size' => $attachment->file_size,
                    'description' => $attachment->description,
                    'category' => $attachment->category,
                    'is_public' => $attachment->is_public
                ];
            }),
        ];

        return response()->json([
            'status' => 'success',
            'message' => 'Supplier retrieved successfully',
            'data' => $transformedData
        ]);
    }

    public function update(UpdateSupplierRequest $request, Supplier $supplier)
    {
        try {
            // Update the supplier
            $supplier->update($request->validated());

            // Handle addresses
            if ($request->has('billing_address_line1')) {
                $this->updateBillingAddress($supplier, $request);
            }

            if ($request->has('shipping_addresses')) {
                $this->updateShippingAddresses($supplier, $request);
            }

            // Handle contacts
            if ($request->has('contacts')) {
                $this->updateContacts($supplier, $request);
            }

            // Handle attachments
            if ($request->has('attachments')) {
                $this->updateAttachments($supplier, $request);
            }

            // Handle multi-currency opening balances
            if ($request->has('opening_balances')) {
                foreach ($request->input('opening_balances') as $openingBalanceData) {
                    $this->openingBalanceService->setSupplierOpeningBalance(
                        $supplier,
                        $openingBalanceData['currency_id'],
                        $openingBalanceData['opening_amount'],
                        $openingBalanceData['opening_date'] ?? null,
                        $openingBalanceData['notes'] ?? null
                    );
                }
            }

            // Handle multi-currency cheque limits
            if ($request->has('cheque_limits')) {
                foreach ($request->input('cheque_limits') as $chequeLimitData) {
                    $supplier->setChequeLimitForCurrency(
                        $chequeLimitData['currency_id'],
                        $chequeLimitData['max_cheques'],
                        $chequeLimitData['notes'] ?? null
                    );
                }
            }

            // Handle multi-currency credit limits
            if ($request->has('credit_limits')) {
                foreach ($request->input('credit_limits') as $creditLimitData) {
                    $supplier->setCreditLimitForCurrency(
                        $creditLimitData['currency_id'],
                        $creditLimitData['credit_limit'],
                        $creditLimitData['notes'] ?? null
                    );
                }
            }

            // Load relationships for response
            $supplier->load([
                'supplierGroup:id,name',
                'trade:id,name',
                'businessType:id,name',
                'paymentTerm:id,code',
                'paymentMethod:id,code',
                'currency:id,code,name',
                'addresses',
                'contacts',
                'attachments'
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Supplier updated successfully',
                'data' => $supplier
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update supplier',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy(Supplier $supplier)
    {
        try {
            // Delete related records first
            $supplier->addresses()->detach();
            $supplier->contacts()->delete();
            $supplier->attachments()->delete();
            $supplier->openingBalances()->delete();
            
            // Delete the supplier
            $supplier->delete();

            return response()->json([
                'status' => 'success',
                'message' => 'Supplier deleted successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete supplier',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function bulkDelete(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:suppliers,id'
        ]);

        try {
            $suppliers = Supplier::whereIn('id', $request->ids)->get();

            foreach ($suppliers as $supplier) {
                $supplier->addresses()->detach();
                $supplier->contacts()->delete();
                $supplier->attachments()->delete();
            }

            Supplier::whereIn('id', $request->ids)->delete();

            return response()->json([
                'status' => 'success',
                'message' => 'Suppliers deleted successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete suppliers',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function exportExcell()
    {
        try {
            $suppliers = Supplier::with([
                'supplierGroup:id,name',
                'trade:id,name',
                'businessType:id,name',
                'paymentTerm:id,code',
                'paymentMethod:id,code',
                'currency:id,code,name'
            ]);

            if ($suppliers->count() === 0) {
                return response()->json(['message' => 'No suppliers found.'], 404);
            }

            $columns = [
                'id', 'title', 'first_name', 'middle_name', 'last_name', 'display_name', 
                'company_name', 'phone1', 'phone2', 'phone3', 'file_number', 'barcode', 
                'search_terms', 'indicator', 'opening_amount', 'opening_date', 'credit_limit', 
                'payment_day', 'track_payment', 'settlement_method', 'accept_cheques', 
                'max_cheques', 'taxable', 'taxed_from_date', 'taxed_till_date', 
                'subjected_to_tax', 'added_tax', 'is_foreign', 'active', 'add_message', 
                'message', 'notes', 'created_at', 'updated_at'
            ];
            
            $headings = [
                'ID', 'Title', 'First Name', 'Middle Name', 'Last Name', 'Display Name',
                'Company Name', 'Phone 1', 'Phone 2', 'Phone 3', 'File Number', 'Barcode',
                'Search Terms', 'Indicator', 'Opening Amount', 'Opening Date', 'Credit Limit',
                'Payment Day', 'Track Payment', 'Settlement Method', 'Accept Cheques',
                'Max Cheques', 'Taxable', 'Taxed From Date', 'Taxed Till Date',
                'Subjected to Tax', 'Added Tax', 'Is Foreign', 'Active', 'Add Message',
                'Message', 'Notes', 'Created At', 'Updated At'
            ];

            return Excel::download(new Export($suppliers, $columns, $headings), 'suppliers.xlsx');

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to export suppliers',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function exportPdf(ExportPDF $pdfService)
    {
        try {
            $suppliers = Supplier::with([
                'supplierGroup:id,name',
                'trade:id,name',
                'businessType:id,name',
                'paymentTerm:id,code',
                'paymentMethod:id,code',
                'currency:id,code,name'
            ])->get();

            $data = $suppliers->map(function ($supplier) {
                return [
                    'ID' => $supplier->id,
                    'Title' => $supplier->title,
                    'First Name' => $supplier->first_name,
                    'Middle Name' => $supplier->middle_name,
                    'Last Name' => $supplier->last_name,
                    'Display Name' => $supplier->display_name,
                    'Company Name' => $supplier->company_name,
                    'Phone 1' => $supplier->phone1,
                    'Phone 2' => $supplier->phone2,
                    'Phone 3' => $supplier->phone3,
                    'File Number' => $supplier->file_number,
                    'Barcode' => $supplier->barcode,
                    'Search Terms' => is_array($supplier->search_terms) ? implode(', ', $supplier->search_terms) : '',
                    'Trade' => $supplier->trade ? $supplier->trade->name : '',
                    'Supplier Group' => $supplier->supplierGroup ? $supplier->supplierGroup->name : '',
                    'Business Type' => $supplier->businessType ? $supplier->businessType->name : '',
                    'Indicator' => $supplier->indicator,
                    'Currency' => $supplier->currency ? $supplier->currency->code : '',
                    'Opening Amount' => $supplier->opening_amount,
                    'Opening Date' => $supplier->opening_date,
                    'Payment Term' => $supplier->paymentTerm ? $supplier->paymentTerm->code : '',
                    'Payment Method' => $supplier->paymentMethod ? $supplier->paymentMethod->code : '',
                    'Credit Limit' => $supplier->credit_limit,
                    'Payment Day' => $supplier->payment_day,
                    'Track Payment' => $supplier->track_payment,
                    'Settlement Method' => $supplier->settlement_method,
                    'Accept Cheques' => $supplier->accept_cheques ? 'Yes' : 'No',
                    'Max Cheques' => $supplier->max_cheques,
                    'Taxable' => $supplier->taxable ? 'Yes' : 'No',
                    'Taxed From Date' => $supplier->taxed_from_date,
                    'Taxed Till Date' => $supplier->taxed_till_date,
                    'Subjected to Tax' => $supplier->subjected_to_tax ? 'Yes' : 'No',
                    'Added Tax' => $supplier->added_tax,
                    'Is Foreign' => $supplier->is_foreign ? 'Yes' : 'No',
                    'Active' => $supplier->active ? 'Yes' : 'No',
                    'Add Message' => $supplier->add_message ? 'Yes' : 'No',
                    'Message' => $supplier->message,
                    'Notes' => $supplier->notes,
                    'Created At' => $supplier->created_at,
                    'Updated At' => $supplier->updated_at
                ];
            });

            $title = 'Suppliers List';
            $headers = [
                'ID' => 'ID',
                'Title' => 'Title',
                'First Name' => 'First Name',
                'Middle Name' => 'Middle Name',
                'Last Name' => 'Last Name',
                'Display Name' => 'Display Name',
                'Company Name' => 'Company Name',
                'Phone 1' => 'Phone 1',
                'Phone 2' => 'Phone 2',
                'Phone 3' => 'Phone 3',
                'File Number' => 'File Number',
                'Barcode' => 'Barcode',
                'Search Terms' => 'Search Terms',
                'Trade' => 'Trade',
                'Supplier Group' => 'Supplier Group',
                'Business Type' => 'Business Type',
                'Indicator' => 'Indicator',
                'Currency' => 'Currency',
                'Opening Amount' => 'Opening Amount',
                'Opening Date' => 'Opening Date',
                'Payment Term' => 'Payment Term',
                'Payment Method' => 'Payment Method',
                'Credit Limit' => 'Credit Limit',
                'Payment Day' => 'Payment Day',
                'Track Payment' => 'Track Payment',
                'Settlement Method' => 'Settlement Method',
                'Accept Cheques' => 'Accept Cheques',
                'Max Cheques' => 'Max Cheques',
                'Taxable' => 'Taxable',
                'Taxed From Date' => 'Taxed From Date',
                'Taxed Till Date' => 'Taxed Till Date',
                'Subjected to Tax' => 'Subjected to Tax',
                'Added Tax' => 'Added Tax',
                'Is Foreign' => 'Is Foreign',
                'Active' => 'Active',
                'Add Message' => 'Add Message',
                'Message' => 'Message',
                'Notes' => 'Notes',
                'Created At' => 'Created At',
                'Updated At' => 'Updated At'
            ];
            
            $pdf = $pdfService->generatePdf($title, $headers, $data->toArray());
            return $pdf->download('suppliers.pdf');

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to export suppliers',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function importFromExcel(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls'
        ]);

        try {
            $import = new DynamicExcelImport('suppliers');
            Excel::import($import, $request->file('file'));

            return response()->json([
                'status' => 'success',
                'message' => 'Suppliers imported successfully',
                'data' => [
                    'imported_count' => $import->getImportedCount(),
                    'errors' => $import->getSkippedRows()
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to import suppliers',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getNames()
    {
        try {
            $suppliers = Supplier::select('id', 'first_name', 'middle_name', 'last_name', 'company_name', 'display_name')
                ->where('active', true)
                ->get()
                ->map(function ($supplier) {
                    return [
                        'id' => $supplier->id,
                        'name' => $supplier->display_name ?: $supplier->company_name ?: $supplier->getFullNameAttribute()
                    ];
                });

            return response()->json([
                'status' => 'success',
                'message' => 'Supplier names retrieved successfully',
                'data' => $suppliers
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve supplier names',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // Helper methods for address management
    private function createBillingAddress($supplier, $request)
    {
        $address = Address::create([
            'address_line1' => $request->billing_address_line1,
            'address_line2' => $request->billing_address_line2,
            'country_id' => $request->billing_country_id,
            'city_id' => $request->billing_city_id,
            'district_id' => $request->billing_district_id,
            'zone_id' => $request->billing_zone_id,
            'building' => $request->billing_building ?? null,
            'block' => $request->billing_block ?? null,
            'floor' => $request->billing_floor ?? null,
            'side' => $request->billing_side ?? null,
            'apartment' => $request->billing_apartment ?? null,
            'zip_code' => $request->billing_zip_code ?? null,
        ]);

        $supplier->addresses()->attach($address->id, [
            'address_type' => 'billing',
            'is_primary' => true,
            'address_name' => 'Primary Billing Address'
        ]);
    }

    private function createShippingAddresses($supplier, $request)
    {
        foreach ($request->shipping_addresses as $shippingAddress) {
            $address = Address::create([
                'address_line1' => $shippingAddress['address_line1'],
                'address_line2' => $shippingAddress['address_line2'] ?? null,
                'country_id' => $shippingAddress['country_id'] ?? null,
                'city_id' => $shippingAddress['city_id'] ?? null,
                'district_id' => $shippingAddress['district_id'] ?? null,
                'zone_id' => $shippingAddress['zone_id'] ?? null,
                'building' => $shippingAddress['building'] ?? null,
                'block' => $shippingAddress['block'] ?? null,
                'floor' => $shippingAddress['floor'] ?? null,
                'side' => $shippingAddress['side'] ?? null,
                'apartment' => $shippingAddress['apartment'] ?? null,
                'zip_code' => $shippingAddress['zip_code'] ?? null,
            ]);

            $supplier->addresses()->attach($address->id, [
                'address_type' => 'shipping',
                'is_primary' => $shippingAddress['is_primary'] ?? false,
                'address_name' => $shippingAddress['address_name'] ?? 'Shipping Address'
            ]);
        }
    }

    private function createContacts($supplier, $request)
    {
        foreach ($request->contacts as $contactData) {
            $contact = $supplier->contacts()->create([
                'title' => $contactData['title'] ?? null,
                'name' => $contactData['name'],
                'work_phone' => $contactData['work_phone'] ?? null,
                'mobile' => $contactData['mobile'] ?? null,
                'position' => $contactData['position'] ?? null,
                'extension' => $contactData['extension'] ?? null,
                'is_primary' => $contactData['is_primary'] ?? false,
            ]);

            if ($contactData['is_primary'] ?? false) {
                $supplier->setPrimaryContact($contact->id);
            }
        }
    }

    private function createAttachments($supplier, $request)
    {
        foreach ($request->attachments as $attachmentData) {
            $file = $attachmentData['file'];
            $fileName = $file->getClientOriginalName();
            $filePath = $file->store('supplier-attachments', 'public');
            
            $supplier->attachments()->create([
                'file_name' => $fileName,
                'file_path' => $filePath,
                'file_type' => $file->getMimeType(),
                'file_size' => $file->getSize(),
                'description' => $attachmentData['description'] ?? null,
                'category' => $attachmentData['category'] ?? 'general',
                'is_public' => $attachmentData['is_public'] ?? true,
            ]);
        }
    }

    private function updateBillingAddress($supplier, $request)
    {
        // Remove existing billing addresses
        $supplier->billingAddresses()->detach();

        // Create new billing address
        $this->createBillingAddress($supplier, $request);
    }

    private function updateShippingAddresses($supplier, $request)
    {
        // Remove existing shipping addresses
        $supplier->shippingAddresses()->detach();

        // Create new shipping addresses
        $this->createShippingAddresses($supplier, $request);
    }

    private function updateContacts($supplier, $request)
    {
        // Remove existing contacts
        $supplier->contacts()->delete();

        // Create new contacts
        $this->createContacts($supplier, $request);
    }

    private function updateAttachments($supplier, $request)
    {
        // Remove existing attachments
        $supplier->attachments()->delete();

        // Create new attachments
        $this->createAttachments($supplier, $request);
    }
}
