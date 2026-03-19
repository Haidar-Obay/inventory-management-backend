<?php

namespace App\Http\Controllers;

use App\Actions\Supplier\GetSupplierBalanceAction;
use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Http\Requests\Supplier\StoreSupplierRequest;
use App\Http\Requests\Supplier\UpdateSupplierRequest;
use App\Http\Requests\Supplier\UploadSupplierAttachmentsRequest;
use App\Imports\DynamicExcelImport;
use App\Models\Address;
use App\Models\Currency;
use App\Models\Supplier;
use App\Models\SupplierAttachment;
use App\Services\OpeningBalanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class SupplierController extends Controller
{
    use \App\Http\Controllers\Concerns\HasBillingAddressHandling;
    protected $openingBalanceService;

    public function __construct(OpeningBalanceService $openingBalanceService)
    {
        $this->openingBalanceService = $openingBalanceService;
    }

    public function index(Request $request)
    {
        if ($request->query('section') === 'balance') {
            return app(GetSupplierBalanceAction::class)->execute($request);
        }

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
                'invoicing_mode' => $supplier->invoicing_mode,
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
            $validated = $request->validated();
            // Payment terms are per-currency in opening_balances, not on supplier
            unset($validated['payment_term_id'], $validated['payment_method_id'], $validated['allow_credit'], $validated['accept_cheques']);

            // Create the supplier with explicit sequential ID
            $nextId = $this->computeNextAvailableId(Supplier::class, 'id');
            $supplier = new Supplier($validated);
            $supplier->id = $nextId;
            $supplier->save();

            // Handle addresses
            if ($this->hasAnyBillingField($request)) {
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
                        $openingBalanceData['notes'] ?? null,
                        $openingBalanceData['payment_term_id'] ?? null,
                        $openingBalanceData['payment_method_id'] ?? null,
                        (bool) ($openingBalanceData['allow_credit'] ?? false),
                        $openingBalanceData['payment_day'] ?? null,
                        $openingBalanceData['track_payment'] ?? 'no',
                        $openingBalanceData['settlement_method'] ?? null,
                        (bool) ($openingBalanceData['accept_cheques'] ?? false)
                    );
                }
            }

            // Handle multi-currency cheque limits
            if ($request->input('cheque_limits')) {
                // Get currencies that have opening balances from the request
                $openingBalanceCurrencies = collect($request->input('opening_balances', []))
                    ->pluck('currency_id')
                    ->filter()
                    ->toArray();

                foreach ($request->input('cheque_limits') as $chequeLimitData) {
                    // Skip empty, null, or zero values (cleared fields)
                    $maxCheques = $chequeLimitData['max_cheques'] ?? null;
                    if (empty($maxCheques) || $maxCheques === '' || $maxCheques === null) {
                        continue;
                    }

                    // Check if this currency has an opening balance
                    if (in_array($chequeLimitData['currency_id'], $openingBalanceCurrencies)) {
                        try {
                            $supplierCheque = \App\Models\SupplierChequeLimit::create([
                                'supplier_id' => $supplier->id,
                                'currency_id' => $chequeLimitData['currency_id'],
                                'max_cheques' => $maxCheques,
                                'used_cheques' => 0,
                                'available_cheques' => $maxCheques,
                                'notes' => $chequeLimitData['notes'] ?? null,
                                'is_active' => true,
                            ]);
                        } catch (\Exception $e) {
                            // Re-throw the exception to trigger transaction rollback
                            throw new \Exception('Cheque limit validation failed: '.$e->getMessage());
                        }
                    }
                }
            }

            // Handle multi-currency credit limits - only for currencies with allow_credit=true
            if ($request->input('credit_limits')) {
                foreach ($request->input('credit_limits') as $creditLimitData) {
                    $currencyId = $creditLimitData['currency_id'] ?? null;
                    if ($currencyId && $supplier->hasAllowCreditForCurrency($currencyId)) {
                        try {
                            $supplier->setCreditLimitForCurrency(
                                $currencyId,
                                $creditLimitData['credit_limit'],
                                $creditLimitData['notes'] ?? null
                            );
                        } catch (\Exception $e) {
                            throw new \Exception('Credit limit validation failed: '.$e->getMessage());
                        }
                    }
                }
            }

            // Load relationships for response (payment terms are on openingBalances, not supplier)
            $supplier->load([
                'supplierGroup:id,name',
                'trade:id,name',
                'businessType:id,name',
                'currency:id,code,name',
                'addresses',
                'shippingAddresses',
                'contacts',
                'attachments',
                'openingBalances.currency:id,code,name',
                'openingBalances.paymentTerm:id,code,name',
                'openingBalances.paymentMethod:id,code,name',
                'creditLimits.currency:id,code,name',
                'chequeLimits.currency:id,code,name',
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

    /**
     * Upload attachments for a supplier (dedicated endpoint; use after create/update without files).
     */
    public function uploadAttachments(UploadSupplierAttachmentsRequest $request, Supplier $supplier)
    {
        $tenantId = tenant('id');
        $files = $request->file('attachments');
        if (! is_array($files)) {
            $files = $files ? [$files] : [];
        }
        $metadata = [];
        if ($request->has('data')) {
            $decoded = json_decode($request->input('data'), true);
            $metadata = $decoded['attachments'] ?? $decoded ?? [];
        }
        $created = [];
        foreach ($files as $index => $file) {
            if (! $file || ! $file->isValid()) {
                continue;
            }
            $path = Storage::disk('public')->putFile(
                "tenants/{$tenantId}/suppliers/{$supplier->id}/attachments",
                $file
            );
            $meta = $metadata[$index] ?? [];
            $attachment = SupplierAttachment::create([
                'supplier_id' => $supplier->id,
                'file_name' => $file->getClientOriginalName(),
                'file_path' => url(Storage::url($path)),
                'file_type' => $file->getMimeType(),
                'file_size' => $file->getSize(),
                'description' => $meta['description'] ?? '',
                'category' => $meta['category'] ?? 'document',
                'is_public' => $meta['is_public'] ?? true,
            ]);
            $created[] = $attachment;
        }

        return response()->json([
            'status' => true,
            'message' => 'Attachments uploaded successfully.',
            'data' => $created,
        ], 201);
    }

    /**
     * List attachments for a supplier.
     */
    public function getAttachments(Supplier $supplier)
    {
        $attachments = $supplier->attachments()->orderBy('created_at', 'desc')->get();

        return response()->json([
            'status' => true,
            'message' => 'Attachments fetched successfully.',
            'data' => $attachments,
        ]);
    }

    /**
     * Delete a supplier attachment.
     */
    public function deleteAttachment(Supplier $supplier, SupplierAttachment $attachment)
    {
        if ($attachment->supplier_id !== (int) $supplier->id) {
            return response()->json([
                'status' => false,
                'message' => 'Attachment does not belong to this supplier.',
            ], 403);
        }
        $filePath = str_replace(url('storage/'), '', $attachment->file_path);
        $filePath = str_replace(url('/storage/'), '', $filePath);
        if (Storage::disk('public')->exists($filePath)) {
            Storage::disk('public')->delete($filePath);
        }
        $attachment->delete();

        return response()->json([
            'status' => true,
            'message' => 'Attachment deleted successfully.',
        ]);
    }

    public function show(Supplier $supplier)
    {
        $supplier->load([
            'supplierGroup:id,name,code,active',
            'trade:id,name,code,active',
            // 'business_types' table does not have an 'active' column, so we only select existing columns
            'businessType:id,name,code',
            'currency:id,code,name,iso_code,symbol,active',
            'addresses:id,address_line1,address_line2,country_id,city_id,district_id,zone_id,building,block,floor,side,appartment,zip_code',
            'addresses.country:id,name',
            'addresses.city:id,name',
            'addresses.district:id,name',
            'addresses.zone:id,name',
            'billingAddresses:id,address_line1,address_line2,country_id,city_id,district_id,zone_id,building,block,floor,side,appartment,zip_code',
            'billingAddresses.country:id,name',
            'billingAddresses.city:id,name',
            'billingAddresses.district:id,name',
            'billingAddresses.zone:id,name',
            'shippingAddresses:id,address_line1,address_line2,country_id,city_id,district_id,zone_id,building,block,floor,side,appartment,zip_code',
            'shippingAddresses.country:id,name',
            'shippingAddresses.city:id,name',
            'shippingAddresses.district:id,name',
            'shippingAddresses.zone:id,name',
            'primaryBillingAddress:id,address_line1,address_line2,country_id,city_id,district_id,zone_id,building,block,floor,side,appartment,zip_code',
            'primaryBillingAddress.country:id,name',
            'primaryBillingAddress.city:id,name',
            'primaryBillingAddress.district:id,name',
            'primaryBillingAddress.zone:id,name',
            'primaryShippingAddress:id,address_line1,address_line2,country_id,city_id,district_id,zone_id,building,block,floor,side,appartment,zip_code',
            'primaryShippingAddress.country:id,name',
            'primaryShippingAddress.city:id,name',
            'primaryShippingAddress.district:id,name',
            'primaryShippingAddress.zone:id,name',
            'primaryContact:id,name,title,work_phone,mobile,position,extension,is_primary',
            'contacts:id,name,title,work_phone,mobile,position,extension,is_primary',
            'attachments:id,supplier_id,file_name,file_path,file_type,file_size,description,category,is_public,created_at,updated_at',
            // Opening balances with currency and payment terms
            'openingBalances:id,currency_id,opening_amount,opening_date,notes,payment_term_id,payment_method_id,allow_credit,payment_day,track_payment,settlement_method,is_active,currency_id',
            'openingBalances.currency:id,code,name,iso_code',
            'openingBalances.paymentTerm:id,code,name,nb_days',
            'openingBalances.paymentMethod:id,code,name',
            // Credit limits with currency
            'creditLimits:id,currency_id,credit_limit,used_credit,available_credit,notes,is_active',
            'creditLimits.currency:id,code,name,iso_code',
            // Cheque limits with currency
            'chequeLimits:id,currency_id,max_cheques,used_cheques,available_cheques,notes,is_active',
            'chequeLimits.currency:id,code,name,iso_code',
        ]);

        // Load active opening balances with currency and payment terms
        $openingBalances = $supplier->openingBalances()
            ->where('is_active', true)
            ->with([
                'currency:id,code,name,iso_code',
                'paymentTerm:id,code,name,nb_days',
                'paymentMethod:id,code,name',
            ])
            ->get();

        // Load active credit limits and cheque limits with currency (like customer controller)
        $creditLimits = $supplier->creditLimits()
            ->where('is_active', true)
            ->with('currency:id,code,name,iso_code')
            ->get();
        $chequeLimits = $supplier->chequeLimits()
            ->where('is_active', true)
            ->with('currency:id,code,name,iso_code')
            ->get();

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
            'invoicing_mode' => $supplier->invoicing_mode,
            'opening_amount' => $supplier->opening_amount,
            'opening_date' => $supplier->opening_date,
            'credit_limit' => $supplier->credit_limit,
            'accept_cheques' => $supplier->openingBalances()->active()->where('accept_cheques', true)->exists(),
            'max_cheques' => $supplier->max_cheques,
            'taxable' => $supplier->taxable,
            'taxed_from_date' => $supplier->taxed_from_date,
            'taxed_till_date' => $supplier->taxed_till_date,
            'subjected_to_tax' => $supplier->subjected_to_tax,
            'added_tax' => $supplier->added_tax,
            'exempted' => $supplier->exempted,
            'exempted_from' => $supplier->exempted_from,
            'exemption_reference' => $supplier->exemption_reference,
            'exempted_from_date' => $supplier->exempted_from_date,
            'exempted_till_date' => $supplier->exempted_till_date,
            'catalog' => $supplier->catalog,
            'is_foreign' => $supplier->is_foreign,
            'active' => $supplier->active,
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
            'payment_term' => $supplier->openingBalances->first()?->paymentTerm ? [
                'id' => $supplier->openingBalances->first()->paymentTerm->id,
                'code' => $supplier->openingBalances->first()->paymentTerm->code,
                'name' => $supplier->openingBalances->first()->paymentTerm->name,
                'nb_days' => $supplier->openingBalances->first()->paymentTerm->nb_days,
                'active' => $supplier->openingBalances->first()->paymentTerm->active,
            ] : null,
            'payment_method' => $supplier->openingBalances->first()?->paymentMethod ? [
                'id' => $supplier->openingBalances->first()->paymentMethod->id,
                'code' => $supplier->openingBalances->first()->paymentMethod->code,
                'name' => $supplier->openingBalances->first()->paymentMethod->name,
                'active' => $supplier->openingBalances->first()->paymentMethod->active,
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

            // Billing addresses with full details (sorted: primary first, then others)
            'billing_addresses' => $supplier->billingAddresses
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
                    ];
                }),

            // Shipping addresses with full details (sorted: primary first, then others)
            'shipping_addresses' => $supplier->shippingAddresses
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
                    ];
                }),

            // Primary billing address with full details (belongsToMany -> use first())
            'primary_billing_address' => ($primaryBilling = $supplier->primaryBillingAddress()->first()) ? [
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
                'country' => $primaryBilling->country ? [
                    'id' => $primaryBilling->country->id,
                    'name' => $primaryBilling->country->name,
                ] : null,
                'city' => $primaryBilling->city ? [
                    'id' => $primaryBilling->city->id,
                    'name' => $primaryBilling->city->name,
                ] : null,
                'district' => $primaryBilling->district ? [
                    'id' => $primaryBilling->district->id,
                    'name' => $primaryBilling->district->name,
                ] : null,
                'zone' => $primaryBilling->zone ? [
                    'id' => $primaryBilling->zone->id,
                    'name' => $primaryBilling->zone->name,
                ] : null,
            ] : null,

            // Primary shipping address (belongsToMany -> use first())
            'primary_shipping_address' => ($primaryShipping = $supplier->primaryShippingAddress()->first()) ? [
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
                'country' => $primaryShipping->country ? [
                    'id' => $primaryShipping->country->id,
                    'name' => $primaryShipping->country->name,
                ] : null,
                'city' => $primaryShipping->city ? [
                    'id' => $primaryShipping->city->id,
                    'name' => $primaryShipping->city->name,
                ] : null,
                'district' => $primaryShipping->district ? [
                    'id' => $primaryShipping->district->id,
                    'name' => $primaryShipping->district->name,
                ] : null,
                'zone' => $primaryShipping->zone ? [
                    'id' => $primaryShipping->zone->id,
                    'name' => $primaryShipping->zone->name,
                ] : null,
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

            // All contacts with full details - always query fresh, like in CustomerController
            'contacts' => $supplier->contacts()->get()->map(function ($contact) {
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

            // Opening balances with currency info (multi-currency table)
            // Use active rows from supplier_opening_balances; if none exist, fall back to legacy single opening_amount/opening_date
            'opening_balances' => $openingBalances->isNotEmpty()
                ? $openingBalances->map(function ($openingBalance) {
                    return [
                        'id' => $openingBalance->id,
                        'currency_id' => $openingBalance->currency_id,
                        'currency_code' => optional($openingBalance->currency)->code,
                        'currency_name' => optional($openingBalance->currency)->name,
                        'currency_iso_code' => optional($openingBalance->currency)->iso_code,
                        'opening_amount' => $openingBalance->opening_amount,
                        'opening_date' => $openingBalance->opening_date,
                        'notes' => $openingBalance->notes,
                        'payment_term_id' => $openingBalance->payment_term_id,
                        'payment_method_id' => $openingBalance->payment_method_id,
                        'allow_credit' => $openingBalance->allow_credit,
                        'payment_day' => $openingBalance->payment_day,
                        'track_payment' => $openingBalance->track_payment,
                        'settlement_method' => $openingBalance->settlement_method,
                        'accept_cheques' => (bool) $openingBalance->accept_cheques,
                        'payment_term' => $openingBalance->paymentTerm ? [
                            'id' => $openingBalance->paymentTerm->id,
                            'code' => $openingBalance->paymentTerm->code,
                            'name' => $openingBalance->paymentTerm->name,
                            'nb_days' => $openingBalance->paymentTerm->nb_days,
                        ] : null,
                        'payment_method' => $openingBalance->paymentMethod ? [
                            'id' => $openingBalance->paymentMethod->id,
                            'code' => $openingBalance->paymentMethod->code,
                            'name' => $openingBalance->paymentMethod->name,
                        ] : null,
                        'is_active' => $openingBalance->is_active,
                    ];
                })
                : (
                    ! is_null($supplier->opening_amount)
                        ? collect([[
                            'id' => null,
                            'currency_id' => $supplier->currency_id,
                            'currency_code' => optional($supplier->currency)->code,
                            'currency_name' => optional($supplier->currency)->name,
                            'currency_iso_code' => optional($supplier->currency)->iso_code,
                            'opening_amount' => $supplier->opening_amount,
                            'opening_date' => $supplier->opening_date,
                            'notes' => null,
                            'is_active' => true,
                        ]])
                        : collect([])
                ),

            // Credit limits with currency info (use explicitly loaded collection)
            'credit_limits' => $creditLimits->map(function ($creditLimit) {
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

            // Cheque limits with currency info (use explicitly loaded collection)
            'cheque_limits' => $chequeLimits->map(function ($chequeLimit) {
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

    /**
     * Get supplier data optimized for purchase invoice
     * Returns only essential fields needed for purchase invoice creation
     *
     * @return \Illuminate\Http\JsonResponse
     */
    /**
     * Get items related to a supplier with costs and purchase UOM
     * Optimized endpoint for loading supplier items in purchase invoice
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getItems(Supplier $supplier)
    {
        // Get items related to this supplier with pivot data (cost)
        $items = $supplier->items()
            ->where('items.active', true) // Only active items
            ->select([
                'items.id',
                'items.code',
                'items.name',
                'items.purchase_description',
                'items.purchase_uom_id',
                'items.tax_group_id',
            ])
            ->withPivot(['cost', 'original_code', 'currency', 'is_primary'])
            ->with([
                // Load purchase UOM
                'purchaseUom:id,name',
                // Load tax group
                'taxGroup:id,code,name,value',
                // Load all UOMs with pivot data (we'll filter to purchase UOM in PHP)
                'unitOfMeasurements' => function ($query) {
                    $query->select([
                        'unit_of_measurements.id',
                        'unit_of_measurements.name',
                    ])
                        ->withPivot([
                            'id',
                            'operation',
                            'conversion',
                            'price_1',
                            'net_weight',
                            'net_volume',
                        ]);
                },
            ])
            ->orderBy('items.code')
            ->get();

        // Get all item UOM pivot IDs for batch barcode loading
        $itemUomPivotIds = [];
        foreach ($items as $item) {
            foreach ($item->unitOfMeasurements as $uom) {
                if ($uom->pivot && $uom->pivot->id) {
                    $itemUomPivotIds[] = $uom->pivot->id;
                }
            }
        }

        // Batch load barcodes
        $barcodesByPivotId = [];
        if (! empty($itemUomPivotIds)) {
            $barcodes = \App\Models\ItemBarcode::whereIn('item_unit_of_measurement_id', $itemUomPivotIds)
                ->select('item_unit_of_measurement_id', 'barcode')
                ->get()
                ->groupBy('item_unit_of_measurement_id');

            foreach ($barcodes as $pivotId => $barcodeGroup) {
                $barcodesByPivotId[$pivotId] = $barcodeGroup->pluck('barcode')->toArray();
            }
        }

        // Transform items to include purchase UOM data
        $transformedItems = $items->map(function ($item) use ($barcodesByPivotId) {
            // Get purchase UOM, or use first available UOM if purchase UOM doesn't exist
            $purchaseUom = $item->purchaseUom;
            $uomToUse = $purchaseUom;

            // If no purchase UOM, try to use the first available UOM
            if (! $uomToUse && $item->unitOfMeasurements->isNotEmpty()) {
                $uomToUse = $item->unitOfMeasurements->first();
            }

            // Get UOM pivot data (from item_unit_of_measurement)
            $uomPivot = null;
            if ($uomToUse) {
                $uomPivot = $item->unitOfMeasurements->firstWhere('id', $uomToUse->id);
            }

            // Get supplier cost from pivot
            $supplierCost = $item->pivot->cost ?? null;
            $supplierCurrency = $item->pivot->currency ?? null;

            // Look up currency ID from currency code
            $currencyId = null;
            if ($supplierCurrency) {
                $currency = \App\Models\Currency::where('code', $supplierCurrency)->first();
                $currencyId = $currency?->id;
            }

            // Get barcodes for UOM
            $barcodes = [];
            if ($uomPivot && $uomPivot->pivot && $uomPivot->pivot->id) {
                $pivotId = $uomPivot->pivot->id;
                $barcodes = $barcodesByPivotId[$pivotId] ?? [];
            }

            return [
                'id' => $item->id,
                'code' => $item->code,
                'name' => $item->name,
                'purchase_description' => $item->purchase_description,
                'supplier_cost' => $supplierCost ? (float) $supplierCost : null,
                'supplier_currency' => $supplierCurrency,
                'supplier_currency_id' => $currencyId,
                'tax_group' => $item->taxGroup ? [
                    'id' => $item->taxGroup->id,
                    'code' => $item->taxGroup->code,
                    'name' => $item->taxGroup->name,
                    'value' => (float) $item->taxGroup->value,
                ] : null,
                'purchase_uom' => $uomToUse ? [
                    'id' => $uomToUse->id,
                    'name' => $uomToUse->name,
                    'conversion' => $uomPivot?->pivot?->conversion ? (float) $uomPivot->pivot->conversion : 1,
                    'operation' => $uomPivot?->pivot?->operation ?? 'multiply',
                    'price_1' => $uomPivot?->pivot?->price_1 ? (float) $uomPivot->pivot->price_1 : 0,
                    'net_weight' => $uomPivot?->pivot?->net_weight ? (float) $uomPivot->pivot->net_weight : 0,
                    'net_volume' => $uomPivot?->pivot?->net_volume ? (float) $uomPivot->pivot->net_volume : 0,
                    'barcodes' => $barcodes,
                ] : null,
            ];
        })->filter()->values(); // Remove nulls and reindex

        return response()->json([
            'status' => true,
            'message' => 'Supplier items fetched successfully.',
            'data' => $transformedItems,
        ]);
    }

    public function getForPurchaseInvoice(Supplier $supplier)
    {
        // Load only essential relationships (payment terms are on openingBalances, not supplier)
        $supplier->load([
            'openingBalances.paymentTerm:id,code,name,nb_days,active',
        ]);

        // Load active opening balances with currency (for currency selection)
        $openingBalances = $supplier->openingBalances()
            ->where('is_active', true)
            ->with('currency:id,code,name,iso_code')
            ->get();

        // Transform opening balances to include flattened currency fields, is_primary, and payment_term per currency
        $openingBalancesData = $openingBalances->map(function ($balance) {
            $currency = $balance->currency;
            $paymentTerm = $balance->paymentTerm;
            $row = [
                'id' => $balance->id,
                'currency_id' => $balance->currency_id,
                'currency_code' => $currency->code ?? null,
                'currency_name' => $currency->name ?? null,
                'currency_iso_code' => $currency->iso_code ?? null,
                'is_primary' => $currency->isPrimary(),
                'opening_amount' => $balance->opening_amount,
                'opening_date' => $balance->opening_date,
                'notes' => $balance->notes,
                'accept_cheques' => (bool) $balance->accept_cheques,
                'is_active' => $balance->is_active,
                'payment_term_id' => $balance->payment_term_id,
                'payment_term' => $paymentTerm ? [
                    'id' => $paymentTerm->id,
                    'code' => $paymentTerm->code,
                    'name' => $paymentTerm->name,
                    'nb_days' => $paymentTerm->nb_days,
                    'active' => $paymentTerm->active ?? true,
                ] : null,
            ];

            return $row;
        });

        // Return only essential data for purchase invoice.
        // opening_balances already contains currency + payment_term per row; no separate currencies array.
        $data = [
            'id' => $supplier->id,
            'display_name' => $supplier->display_name,
            'company_name' => $supplier->company_name,
            'phone1' => $supplier->phone1, // For help popover
            'invoicing_mode' => $supplier->invoicing_mode,
            'payment_term' => $supplier->openingBalances->first()?->paymentTerm ? [
                'id' => $supplier->openingBalances->first()->paymentTerm->id,
                'code' => $supplier->openingBalances->first()->paymentTerm->code,
                'name' => $supplier->openingBalances->first()->paymentTerm->name,
                'nb_days' => $supplier->openingBalances->first()->paymentTerm->nb_days,
                'active' => $supplier->openingBalances->first()->paymentTerm->active,
            ] : null,
            'opening_balances' => $openingBalancesData,
        ];

        return response()->json([
            'status' => 'success',
            'message' => 'Supplier data for purchase invoice retrieved successfully',
            'data' => $data,
        ]);
    }

    public function update(UpdateSupplierRequest $request, Supplier $supplier)
    {
        try {
            // bar_code is the canonical input
            $validated = $request->validated();
            // Payment terms are per-currency in opening_balances, not on supplier
            unset($validated['payment_term_id'], $validated['payment_method_id'], $validated['allow_credit'], $validated['accept_cheques']);

            // Update the supplier
            $supplier->update($validated);

            // Handle addresses
            $this->updateBillingAddress($supplier, $request);

            if ($request->has('shipping_addresses')) {
                $this->updateShippingAddresses($supplier, $request);
            } else {
                // Remove all shipping addresses if not provided (user cleared all fields)
                $shippingAddresses = $supplier->shippingAddresses()->get();
                foreach ($shippingAddresses as $address) {
                    $supplier->addresses()->detach($address->id);
                    $address->delete();
                }
            }

            // Handle contacts
            if ($request->has('contacts')) {
                $this->updateContacts($supplier, $request);
            }

            // Handle attachments
            // Check for files OR JSON attachments array (when editing without new files)
            // Also check if files exist using file() method (more reliable for FormData)
            $hasAttachments = $request->hasFile('attachments')
                || $request->hasFile('attachments.*')
                || $request->file('attachments') !== null
                || $request->file('attachments.*') !== null
                || $request->has('attachments');

            // Always try to call updateAttachments - it will handle the logic internally
            // This ensures we don't miss files that might not be detected by hasFile()
            Log::info('Supplier update: Checking attachments', [
                'hasFile_attachments' => $request->hasFile('attachments'),
                'hasFile_attachments_dot' => $request->hasFile('attachments.*'),
                'file_attachments' => $request->file('attachments') !== null ? 'exists' : 'null',
                'file_attachments_dot' => $request->file('attachments.*') !== null ? 'exists' : 'null',
                'has_attachments' => $request->has('attachments'),
                'has_data' => $request->has('data'),
                'input_data_exists' => $request->input('data') !== null,
                'all_files_keys' => array_keys($request->allFiles()),
                'content_type' => $request->header('Content-Type'),
            ]);
            $this->updateAttachments($supplier, $request);

            // Handle multi-currency opening balances (replace with request list: delete removed, then upsert each)
            if ($request->has('opening_balances')) {
                $requestCurrencyIds = collect($request->input('opening_balances', []))
                    ->pluck('currency_id')
                    ->filter(fn ($id) => $id !== null && $id !== '')
                    ->unique()
                    ->values()
                    ->toArray();
                $supplier->openingBalances()->whereNotIn('currency_id', $requestCurrencyIds)->delete();

                foreach ($request->input('opening_balances') as $openingBalanceData) {
                    $this->openingBalanceService->setSupplierOpeningBalance(
                        $supplier,
                        $openingBalanceData['currency_id'],
                        $openingBalanceData['opening_amount'],
                        $openingBalanceData['opening_date'] ?? null,
                        $openingBalanceData['notes'] ?? null,
                        $openingBalanceData['payment_term_id'] ?? null,
                        $openingBalanceData['payment_method_id'] ?? null,
                        (bool) ($openingBalanceData['allow_credit'] ?? false),
                        $openingBalanceData['payment_day'] ?? null,
                        $openingBalanceData['track_payment'] ?? 'no',
                        $openingBalanceData['settlement_method'] ?? null,
                        (bool) ($openingBalanceData['accept_cheques'] ?? false)
                    );
                }
            }

            // Handle multi-currency cheque limits - update existing or create new
            if ($request->has('cheque_limits') && is_array($request->input('cheque_limits'))) {
                $openingBalanceCurrencies = collect($request->input('opening_balances', []))
                    ->pluck('currency_id')
                    ->filter()
                    ->toArray();

                $existingChequeLimits = $supplier->chequeLimits()->get()->keyBy('currency_id');
                $incomingCurrencyIds = [];

                foreach ($request->input('cheque_limits') as $chequeLimitData) {
                    $maxCheques = $chequeLimitData['max_cheques'] ?? null;
                    if (empty($maxCheques) || $maxCheques === '' || $maxCheques === null) {
                        continue;
                    }

                    $currencyId = $chequeLimitData['currency_id'] ?? null;
                    if ($currencyId && in_array($currencyId, $openingBalanceCurrencies)) {
                        try {
                            $existing = $existingChequeLimits->get($currencyId);
                            if ($existing) {
                                $existing->update([
                                    'max_cheques' => $maxCheques,
                                    'available_cheques' => max(0, $maxCheques - $existing->used_cheques),
                                    'notes' => $chequeLimitData['notes'] ?? $existing->notes,
                                    'is_active' => true,
                                ]);
                            } else {
                                \App\Models\SupplierChequeLimit::create([
                                    'supplier_id' => $supplier->id,
                                    'currency_id' => $currencyId,
                                    'max_cheques' => $maxCheques,
                                    'used_cheques' => 0,
                                    'available_cheques' => $maxCheques,
                                    'notes' => $chequeLimitData['notes'] ?? null,
                                    'is_active' => true,
                                ]);
                            }
                            $incomingCurrencyIds[] = $currencyId;
                        } catch (\Exception $e) {
                            throw new \Exception('Cheque limit validation failed: '.$e->getMessage());
                        }
                    }
                }

                $supplier->chequeLimits()->whereNotIn('currency_id', $incomingCurrencyIds)->delete();
            } else {
                $supplier->chequeLimits()->delete();
            }

            // Remove credit limits for currencies where allow_credit is false
            if ($request->has('opening_balances')) {
                $currenciesWithAllowCredit = collect($request->input('opening_balances', []))
                    ->filter(fn ($ob) => (bool) ($ob['allow_credit'] ?? false))
                    ->map(function ($ob) {
                        $currencyId = $ob['currency_id'] ?? null;
                        if ($currencyId) {
                            return (int) $currencyId;
                        }
                        $code = $ob['currency'] ?? null;
                        if (! $code) {
                            return;
                        }
                        $currency = is_numeric($code)
                            ? \App\Models\Currency::find($code)
                            : \App\Models\Currency::where('code', $code)->first();

                        return $currency?->id;
                    })
                    ->filter()
                    ->unique()
                    ->values()
                    ->toArray();
                if (empty($currenciesWithAllowCredit)) {
                    $supplier->creditLimits()->delete();
                } else {
                    $supplier->creditLimits()->whereNotIn('currency_id', $currenciesWithAllowCredit)->delete();
                }
            }

            // Handle multi-currency credit limits - only for currencies with allow_credit=true
            if ($request->has('credit_limits')) {
                foreach ($request->input('credit_limits') as $creditLimitData) {
                    $currencyId = $creditLimitData['currency_id'] ?? null;
                    if ($currencyId && $supplier->hasAllowCreditForCurrency($currencyId)) {
                        try {
                            $supplier->setCreditLimitForCurrency(
                                $currencyId,
                                $creditLimitData['credit_limit'],
                                $creditLimitData['notes'] ?? null
                            );
                        } catch (\Exception $e) {
                            throw new \Exception('Credit limit validation failed: '.$e->getMessage());
                        }
                    }
                }
            }

            // Load relationships for response (payment terms are on openingBalances, not supplier)
            $supplier->load([
                'supplierGroup:id,name',
                'trade:id,name',
                'businessType:id,name',
                'currency:id,code,name',
                'addresses',
                'shippingAddresses',
                'contacts',
                'attachments',
                'openingBalances.currency:id,code,name',
                'openingBalances.paymentTerm:id,code,name',
                'openingBalances.paymentMethod:id,code,name',
                'creditLimits.currency:id,code,name',
                'chequeLimits.currency:id,code,name',
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
            $supplier->contacts()->delete();
            $supplier->attachments()->delete();
            $supplier->openingBalances()->delete();

            // Addresses will be automatically deleted via cascade foreign key
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
                $supplier->contacts()->delete();
                $supplier->attachments()->delete();

                // Addresses will be automatically deleted via cascade foreign key
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
                'currency:id,code,name',
                'openingBalances.paymentTerm:id,code',
                'openingBalances.paymentMethod:id,code',
            ]);

            if ($suppliers->count() === 0) {
                return response()->json(['message' => 'No suppliers found.'], 404);
            }

            $columns = [
                'id', 'title', 'first_name', 'middle_name', 'last_name', 'display_name',
                'company_name', 'phone1', 'phone2', 'phone3', 'file_number', 'bar_code',
                'search_terms', 'indicator', 'invoicing_mode', 'opening_amount', 'opening_date', 'credit_limit',
                'max_cheques', 'taxable', 'taxed_from_date', 'taxed_till_date',
                'subjected_to_tax', 'added_tax', 'is_foreign', 'active',
                'message', 'notes', 'created_at', 'updated_at',
            ];

            $headings = [
                'ID', 'Title', 'First Name', 'Middle Name', 'Last Name', 'Display Name',
                'Company Name', 'Phone 1', 'Phone 2', 'Phone 3', 'File Number', 'Bar Code',
                'Search Terms', 'Indicator', 'Invoicing Mode', 'Opening Amount', 'Opening Date', 'Credit Limit',
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
                'currency:id,code,name',
                'openingBalances.paymentTerm:id,code',
                'openingBalances.paymentMethod:id,code',
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
                    'Invoicing Mode' => $supplier->invoicing_mode,
                    'Currency' => $supplier->currency ? $supplier->currency->code : '',
                    'Opening Amount' => $supplier->opening_amount,
                    'Opening Date' => $supplier->opening_date,
                    'Payment Term' => $supplier->openingBalances->first()?->paymentTerm?->code ?? '',
                    'Payment Method' => $supplier->openingBalances->first()?->paymentMethod?->code ?? '',
                    'Credit Limit' => $supplier->credit_limit,
                    'Accept Cheques' => $supplier->openingBalances()->active()->where('accept_cheques', true)->exists() ? 'Yes' : 'No',
                    'Max Cheques' => $supplier->max_cheques,
                    'Taxable' => $supplier->taxable ? 'Yes' : 'No',
                    'Taxed From Date' => $supplier->taxed_from_date,
                    'Taxed Till Date' => $supplier->taxed_till_date,
                    'Subjected to Tax' => $supplier->subjected_to_tax ? 'Yes' : 'No',
                    'Added Tax' => $supplier->added_tax,
                    'Is Foreign' => $supplier->is_foreign ? 'Yes' : 'No',
                    'Active' => $supplier->active ? 'Yes' : 'No',
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
                'Invoicing Mode' => 'Invoicing Mode',
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
                    'invoicing_mode',
                    'currency_id',
                    'opening_amount',
                    'opening_date',
                    'credit_limit',
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
                    foreach (['trade_id', 'supplier_group_id', 'business_type_id', 'currency_id', 'contacts_id'] as $fk) {
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
                        'invoicing_mode' => $row['invoicing_mode'] ?? null,
                        'currency_id' => $row['currency_id'] ?? null,
                        'opening_amount' => $row['opening_amount'] ?? null,
                        'opening_date' => $row['opening_date'] ?? null,
                        'credit_limit' => $row['credit_limit'] ?? null,
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
            Log::error('Supplier import failed: '.$e->getMessage(), ['exception' => $e]);

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

    public function getBrief()
    {
        try {
            $suppliers = Supplier::select('id', 'display_name', 'company_name', 'phone1', 'supplier_group_id')
                ->where('active', true)
                ->with(['supplierGroup:id,name'])
                ->orderBy('display_name')
                ->get()
                ->map(function ($supplier) {
                    return [
                        'id' => $supplier->id,
                        'name' => $supplier->display_name ?: $supplier->company_name ?: '',
                        'company_name' => $supplier->company_name,
                        'phone1' => $supplier->phone1,
                        'supplier_group_name' => $supplier->supplierGroup ? $supplier->supplierGroup->name : null,
                        'supplier_group' => $supplier->supplierGroup ? [
                            'name' => $supplier->supplierGroup->name,
                        ] : null,
                    ];
                });

            return response()->json($suppliers);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve supplier brief list',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Lightweight list for Item Supplier Management section: id, name, and currency from first active opening balance.
     */
    public function listForItemSupplierManagement()
    {
        try {
            $suppliers = Supplier::select('id', 'display_name', 'company_name', 'first_name', 'last_name')
                ->where('active', true)
                ->with(['openingBalances' => function ($q) {
                    $q->where('is_active', true)->orderBy('id')->with('currency:id,code,name');
                }])
                ->orderBy('display_name')
                ->get()
                ->map(function ($supplier) {
                    // Get all currencies from active opening balances
                    $currencies = $supplier->openingBalances
                        ->filter(function ($ob) {
                            return $ob->relationLoaded('currency') && $ob->currency;
                        })
                        ->map(function ($ob) {
                            return [
                                'id' => $ob->currency->id,
                                'code' => $ob->currency->code,
                                'name' => $ob->currency->name,
                            ];
                        })
                        ->values()
                        ->toArray();

                    // First currency (for auto-selection)
                    $firstCurrency = ! empty($currencies) ? $currencies[0] : null;

                    return [
                        'id' => $supplier->id,
                        'name' => $supplier->display_name ?: $supplier->company_name ?: trim($supplier->first_name.' '.$supplier->last_name) ?: '',
                        'currency' => $firstCurrency, // First currency for backward compatibility and auto-selection
                        'currencies' => $currencies, // All currencies for dropdown
                    ];
                });

            return response()->json([
                'status' => 'success',
                'message' => 'Suppliers for item supplier management retrieved successfully',
                'data' => $suppliers,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve suppliers for item supplier management',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // Helper methods for address management
    private function createBillingAddress($supplier, $request)
    {
        // Create address in addresses table
        $billingAddress = Address::create([
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
            'appartment' => $request->billing_apartment ?? null,
            'zip_code' => $request->billing_zip_code ?? null,
        ]);

        // Attach to supplier via pivot table with metadata
        $supplier->addresses()->attach($billingAddress->id, [
            'address_type' => 'billing',
            'is_primary' => true,
            'address_name' => $request->billing_address_name ?? 'Primary Billing Address',
            'notes' => $request->billing_notes ?? null,
        ]);
    }

    private function createShippingAddresses($supplier, $request)
    {
        foreach ($request->shipping_addresses as $index => $shippingAddressData) {
            // Create address in addresses table
            $shippingAddress = Address::create([
                'address_line1' => $shippingAddressData['address_line1'],
                'address_line2' => $shippingAddressData['address_line2'] ?? null,
                'country_id' => $shippingAddressData['country_id'] ?? null,
                'city_id' => $shippingAddressData['city_id'] ?? null,
                'district_id' => $shippingAddressData['district_id'] ?? null,
                'zone_id' => $shippingAddressData['zone_id'] ?? null,
                'building' => $shippingAddressData['building'] ?? null,
                'block' => $shippingAddressData['block'] ?? null,
                'floor' => $shippingAddressData['floor'] ?? null,
                'side' => $shippingAddressData['side'] ?? null,
                'appartment' => $shippingAddressData['apartment'] ?? null,
                'zip_code' => $shippingAddressData['zip_code'] ?? null,
            ]);

            // Attach to supplier via pivot table with metadata
            $supplier->addresses()->attach($shippingAddress->id, [
                'address_type' => 'shipping',
                'is_primary' => $shippingAddressData['is_primary'] ?? ($index === 0), // First shipping address is primary
                'address_name' => $shippingAddressData['address_name'] ?? ($index === 0 ? 'Primary Shipping Address' : 'Shipping Address '.($index + 1)),
                'notes' => $shippingAddressData['notes'] ?? null,
            ]);
        }
    }

    private function createContacts($supplier, $request)
    {
        foreach ($request->contacts as $contactData) {
            $contact = \App\Models\SupplierContact::create([
                'supplier_id' => $supplier->id,
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
        if ($this->hasAnyBillingField($request)) {
            $existingBillingPivot = $supplier->primaryBillingAddress()->first();

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
                $existingBillingPivot->update($billingAddressData);
                $supplier->addresses()->updateExistingPivot($existingBillingPivot->id, $billingPivotData);
            } else {
                $billingAddress = Address::create($billingAddressData);
                $supplier->addresses()->attach($billingAddress->id, $billingPivotData);
            }
        } else {
            // All billing fields empty - remove billing address from database
            $billingAddresses = $supplier->billingAddresses()->get();
            foreach ($billingAddresses as $address) {
                $supplier->addresses()->detach($address->id);
                $address->delete();
            }
        }
    }

    private function updateShippingAddresses($supplier, $request)
    {
        // Handle shipping addresses - update existing or create new
        if ($request->has('shipping_addresses')) {
            $shippingAddresses = $request->input('shipping_addresses');
            $existingShippingPivots = $supplier->shippingAddresses()->get()->keyBy('id');
            $newShippingIds = [];

            // First, unset all existing primary shipping addresses to avoid unique constraint violation
            $existingPrimaryShipping = $supplier->primaryShippingAddress()->first();
            if ($existingPrimaryShipping) {
                $supplier->addresses()->updateExistingPivot($existingPrimaryShipping->id, ['is_primary' => false]);
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
                    $supplier->addresses()->updateExistingPivot($existingShipping->id, $shippingPivotData);
                    $newShippingIds[] = $existingShipping->id;
                } else {
                    // CREATE new address
                    $newAddress = Address::create($shippingAddressDataForTable);
                    // Attach to supplier via pivot table
                    $supplier->addresses()->attach($newAddress->id, $shippingPivotData);
                    $newShippingIds[] = $newAddress->id;
                }
            }

            // Delete shipping addresses that were removed
            $addressesToDelete = array_diff($existingShippingPivots->keys()->toArray(), $newShippingIds);
            if (! empty($addressesToDelete)) {
                foreach ($addressesToDelete as $addressId) {
                    $supplier->addresses()->detach($addressId);
                    // Optionally delete the address if not used by others
                    $address = Address::find($addressId);
                    if ($address) {
                        // Check if address is used by other customers or suppliers via pivot tables
                        $usedByCustomers = DB::table('customer_addresses')->where('address_id', $addressId)->exists();
                        $usedBySuppliers = DB::table('supplier_addresses')->where('address_id', $addressId)->exists();
                        if (! $usedByCustomers && ! $usedBySuppliers) {
                            $address->delete();
                        }
                    }
                }
            }
        }
    }

    private function updateContacts($supplier, $request)
    {
        // Update existing or create new (match CustomerController logic)
        $contacts = $request->input('contacts');
        $existingContacts = $supplier->contacts()->get()->keyBy('id');
        $existingContactIds = $existingContacts->keys()->toArray();
        $incomingContactIds = [];

        // Find existing primary contact
        $existingPrimaryContact = $supplier->contacts()->where('is_primary', true)->first();

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
                    'position' => $contactData['position'] ?? null,
                    'extension' => $contactData['extension'] ?? null,
                    'is_primary' => $isPrimary,
                ]);

                if ($isPrimary) {
                    $supplier->setPrimaryContact($contact->id);
                }

                $incomingContactIds[] = $contactId;
            } else {
                // Create new contact
                $contact = \App\Models\SupplierContact::create([
                    'supplier_id' => $supplier->id,
                    'title' => $contactData['title'] ?? null,
                    'name' => $contactData['name'],
                    'work_phone' => $contactData['work_phone'] ?? null,
                    'mobile' => $contactData['mobile'] ?? null,
                    'position' => $contactData['position'] ?? null,
                    'extension' => $contactData['extension'] ?? null,
                    'is_primary' => $isPrimary,
                ]);

                if ($isPrimary) {
                    $supplier->setPrimaryContact($contact->id);
                }

                $incomingContactIds[] = $contact->id;
            }
        }

        // Delete contacts that are no longer in the request
        $contactsToDelete = array_diff($existingContactIds, $incomingContactIds);
        if (! empty($contactsToDelete)) {
            \App\Models\SupplierContact::whereIn('id', $contactsToDelete)
                ->where('supplier_id', $supplier->id)
                ->delete();
        }

        // If contacts_id provided at top level, ensure primary is set
        if ($request->filled('contacts_id')) {
            $supplier->setPrimaryContact((int) $request->input('contacts_id'));
        }
    }

    private function updateAttachments($supplier, $request)
    {
        $tenantId = tenant('id');

        // Check if files are present - try multiple ways Laravel might receive them
        // When using attachments[] in FormData, Laravel might receive it as attachments.*
        $hasFiles = $request->hasFile('attachments')
            || $request->hasFile('attachments.*')
            || $request->file('attachments') !== null
            || $request->file('attachments.*') !== null;

        Log::info('Supplier updateAttachments: Entry', [
            'hasFiles' => $hasFiles,
            'hasFile_attachments' => $request->hasFile('attachments'),
            'hasFile_attachments_dot' => $request->hasFile('attachments.*'),
            'file_attachments' => $request->file('attachments') !== null ? 'exists' : 'null',
            'file_attachments_dot' => $request->file('attachments.*') !== null ? 'exists' : 'null',
        ]);

        // Get attachment data from JSON (includes existing attachments with IDs + new file metadata)
        // Note: prepareForValidation stores attachments in '_attachment_metadata' before unsetting
        // to avoid validation conflict, so we can access it from there
        $attachmentDataFromJson = [];

        // Try multiple ways to get the data
        // 1. Check for _attachment_metadata (set by prepareForValidation)
        if ($request->has('_attachment_metadata')) {
            $attachmentDataFromJson = $request->input('_attachment_metadata', []);
            Log::info('Supplier updateAttachments: Got metadata from _attachment_metadata', ['count' => count($attachmentDataFromJson)]);
        }
        // 2. Try to get from raw data field (even if has('data') returns false, input('data') might work)
        elseif ($request->input('data')) {
            $rawData = $request->input('data');
            if (is_string($rawData)) {
                $data = json_decode($rawData, true);
                $attachmentDataFromJson = $data['attachments'] ?? [];
                Log::info('Supplier updateAttachments: Got metadata from data string', ['count' => count($attachmentDataFromJson)]);
            } elseif (is_array($rawData)) {
                $attachmentDataFromJson = $rawData['attachments'] ?? [];
                Log::info('Supplier updateAttachments: Got metadata from data array', ['count' => count($attachmentDataFromJson)]);
            }
        }
        // 3. Try to get from all() in case data was merged
        elseif (isset($request->all()['attachments'])) {
            $attachmentDataFromJson = $request->all()['attachments'];
            Log::info('Supplier updateAttachments: Got metadata from all()', ['count' => count($attachmentDataFromJson)]);
        }

        // Also check raw request content for FormData
        if (empty($attachmentDataFromJson) && $request->getContent()) {
            parse_str($request->getContent(), $parsed);
            if (isset($parsed['data'])) {
                $data = json_decode($parsed['data'], true);
                $attachmentDataFromJson = $data['attachments'] ?? [];
                Log::info('Supplier updateAttachments: Got metadata from raw content', ['count' => count($attachmentDataFromJson)]);
            }
        }

        // Log for debugging
        Log::info('Supplier updateAttachments', [
            'hasFiles' => $hasFiles,
            'has_attachment_metadata' => $request->has('_attachment_metadata'),
            'attachment_metadata_count' => count($attachmentDataFromJson),
            'file_attachments' => $request->hasFile('attachments'),
            'file_attachments_dot' => $request->hasFile('attachments.*'),
        ]);

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
        $existingAttachments = $supplier->attachments;
        foreach ($existingAttachments as $existingAttachment) {
            if (! in_array($existingAttachment->id, $existingAttachmentIds)) {
                // Delete file from storage
                $relativePath = str_replace(url('/storage'), '', $existingAttachment->file_path);
                Storage::disk('public')->delete($relativePath);
                // Delete attachment record
                $existingAttachment->delete();
            } else {
                // Update existing attachment metadata if provided (match ServiceController logic)
                $metadata = $attachmentMetadataMap[$existingAttachment->id] ?? null;
                if ($metadata) {
                    if (array_key_exists('description', $metadata)) {
                        $existingAttachment->description = $metadata['description'] ?? '';
                    }
                    if (array_key_exists('is_public', $metadata)) {
                        $existingAttachment->is_public = $metadata['is_public'];
                    }
                    if (array_key_exists('category', $metadata)) {
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
        Log::info('Supplier updateAttachments: Checking allFiles()', [
            'allFiles_keys' => array_keys($allFiles),
            'allFiles_count' => count($allFiles),
        ]);

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
                            Log::info('Supplier updateAttachments: Added file', [
                                'key' => $key,
                                'name' => $f->getClientOriginalName(),
                                'size' => $f->getSize(),
                            ]);
                        } else {
                            Log::info('Supplier updateAttachments: Skipped duplicate file', [
                                'key' => $key,
                                'name' => $f->getClientOriginalName(),
                            ]);
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
                Log::info('Supplier updateAttachments: Found files via attachments.*', ['count' => count($files)]);
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
                Log::info('Supplier updateAttachments: Found files via attachments', ['count' => count($files)]);
            }
        }

        Log::info('Supplier updateAttachments files', [
            'files_count' => count($files),
            'newFileMetadata_count' => count($newFileMetadata),
            'hasFiles' => $hasFiles,
        ]);

        // Only process files if we have them AND metadata
        if (count($files) === 0 && count($newFileMetadata) > 0) {
            Log::warning('Supplier updateAttachments: Have metadata but no files!', [
                'allFiles_keys' => array_keys($request->allFiles()),
                'request_method' => $request->method(),
                'content_type' => $request->header('Content-Type'),
            ]);
        }

        // Match uploaded files with metadata (new files come after existing attachments in the array)
        foreach ($files as $index => $file) {
            // Skip if file is null, not valid, or not an instance of UploadedFile
            if (! $file || ! $file->isValid()) {
                Log::warning('Supplier updateAttachments: Invalid file', ['index' => $index]);

                continue;
            }

            try {
                $path = Storage::disk('public')->putFile(
                    "tenants/{$tenantId}/suppliers/{$supplier->id}/attachments",
                    $file
                );

                // Find matching metadata for this file (new files start after existing attachments)
                $metadata = $newFileMetadata[$index] ?? [];
                $description = $metadata['description'] ?? '';
                $category = $metadata['category'] ?? 'document';
                $isPublic = $metadata['is_public'] ?? true;

                $attachment = SupplierAttachment::create([
                    'supplier_id' => $supplier->id,
                    'file_name' => $file->getClientOriginalName(),
                    'file_path' => url(Storage::url($path)),
                    'file_type' => $file->getMimeType(),
                    'file_size' => $file->getSize(),
                    'description' => $description,
                    'category' => $category,
                    'is_public' => $isPublic,
                ]);

                Log::info('Supplier updateAttachments: Created attachment', [
                    'attachment_id' => $attachment->id,
                    'file_name' => $attachment->file_name,
                ]);
            } catch (\Exception $e) {
                Log::error('Supplier updateAttachments: Error creating attachment', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
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
                $existingAttachments = $supplier->attachments;
                foreach ($existingAttachments as $existingAttachment) {
                    if (! in_array($existingAttachment->id, $attachmentIdsToKeep)) {
                        // Delete file from storage
                        $relativePath = str_replace(url('/storage'), '', $existingAttachment->file_path);
                        Storage::disk('public')->delete($relativePath);
                        // Delete attachment record
                        $existingAttachment->delete();
                    } else {
                        // Update existing attachment metadata if provided (match ServiceController logic)
                        $metadata = $attachmentMetadataMap[$existingAttachment->id] ?? null;
                        if ($metadata) {
                            if (array_key_exists('description', $metadata)) {
                                $existingAttachment->description = $metadata['description'] ?? '';
                            }
                            if (array_key_exists('is_public', $metadata)) {
                                $existingAttachment->is_public = $metadata['is_public'];
                            }
                            if (array_key_exists('category', $metadata)) {
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
    }
}
