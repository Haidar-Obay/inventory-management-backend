<?php

namespace App\Http\Controllers;

use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Http\Requests\Supplier\StoreSupplierRequest;
use App\Http\Requests\Supplier\UpdateSupplierRequest;
use App\Imports\DynamicExcelImport;
use App\Models\Address;
use App\Models\Currency;
use App\Models\Supplier;
use App\Models\SupplierAttachment;
use App\Services\OpeningBalanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

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
                'bar_code' => $supplier->bar_code,
                'search_terms' => $supplier->search_terms,
                'indicator' => $supplier->indicator,
                'active' => $supplier->active,
                'notes' => $supplier->notes,
                'is_foreign' => $supplier->is_foreign,
                'supplier_group' => $supplier->supplierGroup ? [
                    'id' => $supplier->supplierGroup->id,
                    'name' => $supplier->supplierGroup->name,
                    'code' => $supplier->supplierGroup->code,
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
                        'is_active' => $openingBalance->is_active,
                    ];
                }),
                'created_at' => $supplier->created_at,
                'updated_at' => $supplier->updated_at,
            ];
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Suppliers retrieved successfully',
            'data' => $transformedData,
        ]);
    }

    public function store(StoreSupplierRequest $request)
    {
        try {
            // bar_code is the canonical input

            // Create the supplier with explicit sequential ID
            $nextId = $this->computeNextAvailableId(Supplier::class, 'id');
            $supplier = new Supplier($request->validated());
            $supplier->id = $nextId;
            $supplier->save();

            // Handle addresses
            $hasAnyBilling = $request->filled('billing_address_line1');
            if (! $hasAnyBilling) {
                foreach ([
                    'billing_country_id', 'billing_city_id', 'billing_district_id', 'billing_zone_id',
                    'billing_building', 'billing_block', 'billing_floor', 'billing_side',
                    'billing_apartment', 'billing_zip_code', 'billing_address_line2', 'billing_address_name', 'billing_notes',
                ] as $k) {
                    if ($request->has($k)) {
                        $hasAnyBilling = true;

                        break;
                    }
                }
            }
            if ($hasAnyBilling) {
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
                'attachments',
                'openingBalances.currency:id,code,name',
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Supplier created successfully',
                'data' => $supplier,
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create supplier',
                'error' => $e->getMessage(),
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
            'addresses.country:id,name',
            'addresses.city:id,name',
            'addresses.district:id,name',
            'addresses.zone:id,name',
            'billingAddresses.country:id,name',
            'billingAddresses.city:id,name',
            'billingAddresses.district:id,name',
            'billingAddresses.zone:id,name',
            'shippingAddresses.country:id,name',
            'shippingAddresses.city:id,name',
            'shippingAddresses.district:id,name',
            'shippingAddresses.zone:id,name',
            'primaryBillingAddress.country:id,name',
            'primaryBillingAddress.city:id,name',
            'primaryBillingAddress.district:id,name',
            'primaryBillingAddress.zone:id,name',
            'primaryShippingAddress.country:id,name',
            'primaryShippingAddress.city:id,name',
            'primaryShippingAddress.district:id,name',
            'primaryShippingAddress.zone:id,name',
            'primaryContact:id,name,title,work_phone,mobile,position,extension,is_primary',
            'contacts:id,name,title,work_phone,mobile,position,extension,is_primary',
            'attachments:id,file_name,file_path,file_type,file_size,description,category,is_public',
            // Opening balances with currency
            'openingBalances:id,currency_id,opening_amount,opening_date,notes,is_active',
            'openingBalances.currency:id,code,name,iso_code',
            // Credit limits with currency
            'creditLimits:id,currency_id,credit_limit,used_credit,available_credit,notes,is_active',
            'creditLimits.currency:id,code,name,iso_code',
            // Cheque limits with currency
            'chequeLimits:id,currency_id,max_cheques,used_cheques,available_cheques,notes,is_active',
            'chequeLimits.currency:id,code,name,iso_code',
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
            'bar_code' => $supplier->bar_code,
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
                'active' => $supplier->supplierGroup->active,
            ] : null,
            'trade' => $supplier->trade ? [
                'id' => $supplier->trade->id,
                'name' => $supplier->trade->name,
                'code' => $supplier->trade->code,
                'active' => $supplier->trade->active,
            ] : null,
            'business_type' => $supplier->businessType ? [
                'id' => $supplier->businessType->id,
                'name' => $supplier->businessType->name,
                'code' => $supplier->businessType->code,
                'active' => $supplier->businessType->active,
            ] : null,
            'payment_term' => $supplier->paymentTerm ? [
                'id' => $supplier->paymentTerm->id,
                'code' => $supplier->paymentTerm->code,
                'name' => $supplier->paymentTerm->name,
                'active' => $supplier->paymentTerm->active,
            ] : null,
            'payment_method' => $supplier->paymentMethod ? [
                'id' => $supplier->paymentMethod->id,
                'code' => $supplier->paymentMethod->code,
                'name' => $supplier->paymentMethod->name,
                'active' => $supplier->paymentMethod->active,
            ] : null,
            'currency' => $supplier->currency ? [
                'id' => $supplier->currency->id,
                'code' => $supplier->currency->code,
                'name' => $supplier->currency->name,
                'iso_code' => $supplier->currency->iso_code,
                'active' => $supplier->currency->active,
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
                    'building' => $address->building,
                    'block' => $address->block,
                    'floor' => $address->floor,
                    'side' => $address->side,
                    'appartment' => $address->appartment,
                    'zip_code' => $address->zip_code,
                    'country' => $address->country ? [
                        'id' => $address->country->id,
                        'name' => $address->country->name,
                    ] : null,
                    'city' => $address->city ? [
                        'id' => $address->city->id,
                        'name' => $address->city->name,
                    ] : null,
                    'district' => $address->district ? [
                        'id' => $address->district->id,
                        'name' => $address->district->name,
                    ] : null,
                    'zone' => $address->zone ? [
                        'id' => $address->zone->id,
                        'name' => $address->zone->name,
                    ] : null,
                    'pivot' => $address->pivot,
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
                    'building' => $address->building,
                    'block' => $address->block,
                    'floor' => $address->floor,
                    'side' => $address->side,
                    'appartment' => $address->appartment,
                    'zip_code' => $address->zip_code,
                    'country' => $address->country ? [
                        'id' => $address->country->id,
                        'name' => $address->country->name,
                    ] : null,
                    'city' => $address->city ? [
                        'id' => $address->city->id,
                        'name' => $address->city->name,
                    ] : null,
                    'district' => $address->district ? [
                        'id' => $address->district->id,
                        'name' => $address->district->name,
                    ] : null,
                    'zone' => $address->zone ? [
                        'id' => $address->zone->id,
                        'name' => $address->zone->name,
                    ] : null,
                    'pivot' => $address->pivot,
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
                    'building' => $address->building,
                    'block' => $address->block,
                    'floor' => $address->floor,
                    'side' => $address->side,
                    'appartment' => $address->appartment,
                    'zip_code' => $address->zip_code,
                    'country' => $address->country ? [
                        'id' => $address->country->id,
                        'name' => $address->country->name,
                    ] : null,
                    'city' => $address->city ? [
                        'id' => $address->city->id,
                        'name' => $address->city->name,
                    ] : null,
                    'district' => $address->district ? [
                        'id' => $address->district->id,
                        'name' => $address->district->name,
                    ] : null,
                    'zone' => $address->zone ? [
                        'id' => $address->zone->id,
                        'name' => $address->zone->name,
                    ] : null,
                    'pivot' => $address->pivot,
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
                'building' => $supplier->primaryBillingAddress->first()->building,
                'block' => $supplier->primaryBillingAddress->first()->block,
                'floor' => $supplier->primaryBillingAddress->first()->floor,
                'side' => $supplier->primaryBillingAddress->first()->side,
                'appartment' => $supplier->primaryBillingAddress->first()->appartment,
                'zip_code' => $supplier->primaryBillingAddress->first()->zip_code,
                'country' => $supplier->primaryBillingAddress->first()->country ? [
                    'id' => $supplier->primaryBillingAddress->first()->country->id,
                    'name' => $supplier->primaryBillingAddress->first()->country->name,
                ] : null,
                'city' => $supplier->primaryBillingAddress->first()->city ? [
                    'id' => $supplier->primaryBillingAddress->first()->city->id,
                    'name' => $supplier->primaryBillingAddress->first()->city->name,
                ] : null,
                'district' => $supplier->primaryBillingAddress->first()->district ? [
                    'id' => $supplier->primaryBillingAddress->first()->district->id,
                    'name' => $supplier->primaryBillingAddress->first()->district->name,
                ] : null,
                'zone' => $supplier->primaryBillingAddress->first()->zone ? [
                    'id' => $supplier->primaryBillingAddress->first()->zone->id,
                    'name' => $supplier->primaryBillingAddress->first()->zone->name,
                ] : null,
                'pivot' => $supplier->primaryBillingAddress->first()->pivot,
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
                'building' => $supplier->primaryShippingAddress->first()->building,
                'block' => $supplier->primaryShippingAddress->first()->block,
                'floor' => $supplier->primaryShippingAddress->first()->floor,
                'side' => $supplier->primaryShippingAddress->first()->side,
                'appartment' => $supplier->primaryShippingAddress->first()->appartment,
                'zip_code' => $supplier->primaryShippingAddress->first()->zip_code,
                'country' => $supplier->primaryShippingAddress->first()->country ? [
                    'id' => $supplier->primaryShippingAddress->first()->country->id,
                    'name' => $supplier->primaryShippingAddress->first()->country->name,
                ] : null,
                'city' => $supplier->primaryShippingAddress->first()->city ? [
                    'id' => $supplier->primaryShippingAddress->first()->city->id,
                    'name' => $supplier->primaryShippingAddress->first()->city->name,
                ] : null,
                'district' => $supplier->primaryShippingAddress->first()->district ? [
                    'id' => $supplier->primaryShippingAddress->first()->district->id,
                    'name' => $supplier->primaryShippingAddress->first()->district->name,
                ] : null,
                'zone' => $supplier->primaryShippingAddress->first()->zone ? [
                    'id' => $supplier->primaryShippingAddress->first()->zone->id,
                    'name' => $supplier->primaryShippingAddress->first()->zone->name,
                ] : null,
                'pivot' => $supplier->primaryShippingAddress->first()->pivot,
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
                'is_primary' => $supplier->primaryContact->is_primary,
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
                    'is_primary' => $contact->is_primary,
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
                    'is_public' => $attachment->is_public,
                ];
            }),

            // Opening balances with currency info
            'opening_balances' => $supplier->openingBalances->map(function ($openingBalance) {
                return [
                    'id' => $openingBalance->id,
                    'currency_id' => $openingBalance->currency_id,
                    'currency_code' => optional($openingBalance->currency)->code,
                    'currency_name' => optional($openingBalance->currency)->name,
                    'currency_iso_code' => optional($openingBalance->currency)->iso_code,
                    'opening_amount' => $openingBalance->opening_amount,
                    'opening_date' => $openingBalance->opening_date,
                    'notes' => $openingBalance->notes,
                    'is_active' => $openingBalance->is_active,
                ];
            }),

            // Credit limits with currency info
            'credit_limits' => $supplier->creditLimits->map(function ($creditLimit) {
                return [
                    'id' => $creditLimit->id,
                    'currency_id' => $creditLimit->currency_id,
                    'currency_code' => optional($creditLimit->currency)->code,
                    'currency_name' => optional($creditLimit->currency)->name,
                    'currency_iso_code' => optional($creditLimit->currency)->iso_code,
                    'credit_limit' => $creditLimit->credit_limit,
                    'used_credit' => $creditLimit->used_credit,
                    'available_credit' => $creditLimit->available_credit,
                    'notes' => $creditLimit->notes,
                    'is_active' => $creditLimit->is_active,
                ];
            }),

            // Cheque limits with currency info
            'cheque_limits' => $supplier->chequeLimits->map(function ($chequeLimit) {
                return [
                    'id' => $chequeLimit->id,
                    'currency_id' => $chequeLimit->currency_id,
                    'currency_code' => optional($chequeLimit->currency)->code,
                    'currency_name' => optional($chequeLimit->currency)->name,
                    'currency_iso_code' => optional($chequeLimit->currency)->iso_code,
                    'max_cheques' => $chequeLimit->max_cheques,
                    'used_cheques' => $chequeLimit->used_cheques,
                    'available_cheques' => $chequeLimit->available_cheques,
                    'notes' => $chequeLimit->notes,
                    'is_active' => $chequeLimit->is_active,
                ];
            }),
        ];

        return response()->json([
            'status' => 'success',
            'message' => 'Supplier retrieved successfully',
            'data' => $transformedData,
        ]);
    }

    public function update(UpdateSupplierRequest $request, Supplier $supplier)
    {
        try {
            // bar_code is the canonical input

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
                'attachments',
                'openingBalances.currency:id,code,name',
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Supplier updated successfully',
                'data' => $supplier,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update supplier',
                'error' => $e->getMessage(),
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
                'message' => 'Supplier deleted successfully',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete supplier',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function bulkDelete(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:suppliers,id',
        ]);

        $ids = $request->input('ids');
        $skipped = [];
        $deleted = 0;

        foreach ($ids as $id) {
            try {
                $supplier = Supplier::find($id);

                if (! $supplier) {
                    $skipped[] = [
                        'id' => $id,
                        'reason' => 'Supplier not found.',
                    ];

                    continue;
                }

                // Delete related data
                $supplier->addresses()->detach();
                $supplier->contacts()->delete();
                $supplier->attachments()->delete();
                $supplier->delete();
                $deleted++;

            } catch (\Exception $e) {
                Log::error('Error deleting supplier '.$id.': '.$e->getMessage());
                $skipped[] = [
                    'id' => $id,
                    'reason' => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'message' => 'Bulk delete completed.',
            'deleted_count' => $deleted,
            'skipped' => $skipped,
        ]);
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
                'currency:id,code,name',
            ]);

            if ($suppliers->count() === 0) {
                return response()->json(['message' => 'No suppliers found.'], 404);
            }

            $columns = [
                'id', 'title', 'first_name', 'middle_name', 'last_name', 'display_name',
                'company_name', 'phone1', 'phone2', 'phone3', 'file_number', 'bar_code',
                'search_terms', 'indicator', 'opening_amount', 'opening_date', 'credit_limit',
                'payment_day', 'track_payment', 'settlement_method', 'accept_cheques',
                'max_cheques', 'taxable', 'taxed_from_date', 'taxed_till_date',
                'subjected_to_tax', 'added_tax', 'is_foreign', 'active', 'add_message',
                'message', 'notes', 'created_at', 'updated_at',
            ];

            $headings = [
                'ID', 'Title', 'First Name', 'Middle Name', 'Last Name', 'Display Name',
                'Company Name', 'Phone 1', 'Phone 2', 'Phone 3', 'File Number', 'Bar Code',
                'Search Terms', 'Indicator', 'Opening Amount', 'Opening Date', 'Credit Limit',
                'Payment Day', 'Track Payment', 'Settlement Method', 'Accept Cheques',
                'Max Cheques', 'Taxable', 'Taxed From Date', 'Taxed Till Date',
                'Subjected to Tax', 'Added Tax', 'Is Foreign', 'Active', 'Add Message',
                'Message', 'Notes', 'Created At', 'Updated At',
            ];

            return Excel::download(new Export($suppliers, $columns, $headings), 'suppliers.xlsx');

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to export suppliers',
                'error' => $e->getMessage(),
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
                'currency:id,code,name',
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
                    'Bar Code' => $supplier->bar_code,
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
                    'Updated At' => $supplier->updated_at,
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
                'Updated At' => 'Updated At',
            ];

            $pdf = $pdfService->generatePdf($title, $headers, $data->toArray());

            return $pdf->download('suppliers.pdf');

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to export suppliers',
                'error' => $e->getMessage(),
            ], 500);
        }
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
                Supplier::truncate();
            }
            $import = new DynamicExcelImport(
                Supplier::class,
                [
                    'title',
                    'first_name',
                    'middle_name',
                    'last_name',
                    'display_name',
                    'company_name',
                    'phone1',
                    'phone2',
                    'phone3',
                    'file_number',
                    'bar_code',
                    'search_terms',
                    'trade_id',
                    'supplier_group_id',
                    'business_type_id',
                    'indicator',
                    'currency_id',
                    'opening_amount',
                    'opening_date',
                    'payment_term_id',
                    'payment_method_id',
                    'credit_limit',
                    'payment_day',
                    'track_payment',
                    'settlement_method',
                    'accept_cheques',
                    'max_cheques',
                    'notes',
                    'taxable',
                    'taxed_from_date',
                    'taxed_till_date',
                    'subjected_to_tax',
                    'added_tax',
                    'catalog',
                    'is_foreign',
                    'active',
                    'add_message',
                    'message',
                    'contacts_id',
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
                    if (($row['phone1'] ?? '') === '') {
                        $errors[] = 'Missing phone1';
                    }
                    // Validate foreign keys as numeric if present
                    foreach (['trade_id', 'supplier_group_id', 'business_type_id', 'currency_id', 'payment_term_id', 'payment_method_id', 'contacts_id'] as $fk) {
                        if (isset($row[$fk]) && $row[$fk] !== '' && ! is_numeric($row[$fk])) {
                            $errors[] = "Invalid $fk: must be numeric ID";
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
                        'supplier_group_id' => $row['supplier_group_id'] ?? null,
                        'business_type_id' => $row['business_type_id'] ?? null,
                        'indicator' => $row['indicator'] ?? null,
                        'currency_id' => $row['currency_id'] ?? null,
                        'opening_amount' => $row['opening_amount'] ?? null,
                        'opening_date' => $row['opening_date'] ?? null,
                        'payment_term_id' => $row['payment_term_id'] ?? null,
                        'payment_method_id' => $row['payment_method_id'] ?? null,
                        'credit_limit' => $row['credit_limit'] ?? null,
                        'payment_day' => $row['payment_day'] ?? null,
                        'track_payment' => $row['track_payment'] ?? null,
                        'settlement_method' => $row['settlement_method'] ?? null,
                        'accept_cheques' => $row['accept_cheques'] ?? null,
                        'max_cheques' => $row['max_cheques'] ?? null,
                        'notes' => $row['notes'] ?? null,
                        'taxable' => $row['taxable'] ?? null,
                        'taxed_from_date' => $parseDate($row['taxed_from_date'] ?? null),
                        'taxed_till_date' => $parseDate($row['taxed_till_date'] ?? null),
                        'subjected_to_tax' => $row['subjected_to_tax'] ?? null,
                        'added_tax' => $row['added_tax'] ?? null,
                        'catalog' => $row['catalog'] ?? null,
                        'is_foreign' => $row['is_foreign'] ?? null,
                        'active' => isset($row['active']) ? (bool) $row['active'] : true,
                        'add_message' => $row['add_message'] ?? null,
                        'message' => $row['message'] ?? null,
                        'contacts_id' => $row['contacts_id'] ?? null,
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
            ]);

        } catch (\Exception $e) {
            // Log the error for debugging
            \Log::error('Supplier import failed: '.$e->getMessage(), ['exception' => $e]);

            return response()->json([
                'success' => false,
                'message' => 'Import failed due to invalid data. Please check your file for invalid or missing references (e.g., payment method, etc.).',
                'error_type' => 'database',
            ], 422);
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
                        'name' => $supplier->display_name ?: $supplier->company_name ?: $supplier->getFullNameAttribute(),
                    ];
                });

            return response()->json([
                'status' => 'success',
                'message' => 'Supplier names retrieved successfully',
                'data' => $suppliers,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve supplier names',
                'error' => $e->getMessage(),
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
            'address_name' => 'Primary Billing Address',
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
                'address_name' => $shippingAddress['address_name'] ?? 'Shipping Address',
            ]);
        }
    }

    private function createContacts($supplier, $request)
    {
        foreach ($request->contacts as $contactData) {
            $nextContactId = $this->computeNextAvailableId(\App\Models\SupplierContact::class, 'id');
            $contact = new \App\Models\SupplierContact([
                'supplier_id' => $supplier->id,
                'title' => $contactData['title'] ?? null,
                'name' => $contactData['name'],
                'work_phone' => $contactData['work_phone'] ?? null,
                'mobile' => $contactData['mobile'] ?? null,
                'position' => $contactData['position'] ?? null,
                'extension' => $contactData['extension'] ?? null,
                'is_primary' => $contactData['is_primary'] ?? false,
            ]);
            $contact->id = $nextContactId;
            $contact->save();

            if ($contactData['is_primary'] ?? false) {
                $supplier->setPrimaryContact($contact->id);
            }
        }
    }

    private function createAttachments($supplier, $request)
    {
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
                    "tenants/{$tenantId}/suppliers/{$supplier->id}/attachments",
                    $file
                );

                // Find matching metadata for this file
                $metadata = $attachmentMetadata[$index] ?? [];
                $description = $metadata['description'] ?? '';

                SupplierAttachment::create([
                    'supplier_id' => $supplier->id,
                    'file_name' => $file->getClientOriginalName(),
                    'file_path' => url(Storage::url($path)),
                    'file_type' => $file->getMimeType(),
                    'file_size' => $file->getSize(),
                    'description' => $description,
                    'category' => 'document',
                ]);
            }
        } elseif ($request->has('attachments')) {
            // Handle attachments with new structure (JSON data)
            foreach ($request->input('attachments') as $attachmentData) {
                // Only create attachment if we have a valid file path or file URL
                $filePath = $attachmentData['file_url'] ?? $attachmentData['file_path'] ?? null;
                if ($filePath && ! empty(trim($filePath))) {
                    SupplierAttachment::create([
                        'supplier_id' => $supplier->id,
                        'file_name' => $attachmentData['file_name'] ?? 'Unknown',
                        'file_path' => $filePath,
                        'file_type' => $attachmentData['file_type'] ?? null,
                        'file_size' => $attachmentData['file_size'] ?? null,
                        'description' => $attachmentData['description'] ?? '',
                        'category' => $attachmentData['category'] ?? 'document',
                    ]);
                }
            }
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
        foreach ($supplier->attachments as $attachment) {
            $relativePath = str_replace(url('/storage'), '', $attachment->file_path);
            Storage::disk('public')->delete($relativePath);
            $attachment->delete();
        }

        // Create new attachments
        $this->createAttachments($supplier, $request);
    }
}
