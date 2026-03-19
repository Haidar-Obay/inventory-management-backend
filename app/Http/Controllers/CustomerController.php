<?php

namespace App\Http\Controllers;

use App\Actions\Customer\GetCustomerAppointmentHistoryAction;
use App\Actions\Customer\GetCustomerAttachmentsAction;
use App\Actions\Customer\GetCustomerBalanceAction;
use App\Actions\Customer\GetCustomerForInvoiceAction;
use App\Actions\Customer\GetCustomerNamesAction;
use App\Actions\Customer\GetCustomerVisitHistoryAction;
use App\Actions\Customer\ShowCustomerFullAction;
use App\Exports\Export;
use App\Exports\ExportPDF;
use App\Http\Requests\Customer\StoreCustomerRequest;
use App\Http\Requests\Customer\UpdateCustomerRequest;
use App\Http\Requests\Customer\UploadCustomerAttachmentsRequest;
use App\Imports\DynamicExcelImport;
use App\Models\Address;
use App\Models\Customer;
use App\Models\CustomerAttachment;
use App\Models\Project;
use App\Services\OpeningBalanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class CustomerController extends Controller
{
    use \App\Http\Controllers\Concerns\HasBillingAddressHandling;

    public function __construct(
        protected OpeningBalanceService $openingBalanceService
    ) {}

    private const INDEX_SECTIONS = ['names', 'balance'];

    private const SHOW_SECTIONS = ['full', 'attachments', 'appointments', 'visits', 'for_invoice'];

    /**
     * List customers. Use ?section=names for id/name/phone list; default is grid data.
     */
    public function index(Request $request)
    {
        $section = $request->query('section');
        if ($section !== null && ! in_array($section, self::INDEX_SECTIONS, true)) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid section. Allowed: '.implode(', ', self::INDEX_SECTIONS).'.',
            ], 422);
        }
        if ($section === 'names') {
            return app(GetCustomerNamesAction::class)->execute();
        }
        if ($section === 'balance') {
            return app(GetCustomerBalanceAction::class)->execute($request);
        }

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
        ])->with([
            'customerGroup:id,name',
            'salesman:id,name',
            'openingBalances.paymentTerm:id,name',
            'openingBalances.paymentMethod:id,name',
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
                'payment_term' => $customer->openingBalances->first()?->paymentTerm ? [
                    'id' => $customer->openingBalances->first()->paymentTerm->id,
                    'name' => $customer->openingBalances->first()->paymentTerm->name,
                ] : null,
                'payment_method' => $customer->openingBalances->first()?->paymentMethod ? [
                    'id' => $customer->openingBalances->first()->paymentMethod->id,
                    'name' => $customer->openingBalances->first()->paymentMethod->name,
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

            // Payment terms are per-currency in opening_balances, not on customer
            unset($validated['payment_term_id'], $validated['payment_method_id'], $validated['allow_credit']);

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
            if ($this->hasAnyBillingField($request)) {
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
                    $currency = \App\Models\Currency::where('code', $openingBalanceData['currency'])->first();
                    if ($currency) {
                        $this->openingBalanceService->setCustomerOpeningBalance(
                            $customer,
                            $currency->id,
                            $openingBalanceData['amount'],
                            $openingBalanceData['date'] ?? null,
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
            }

            // Remove credit limits for currencies where allow_credit is false (no row in customer_credit_limits)
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
                    $customer->creditLimits()->delete();
                } else {
                    $customer->creditLimits()->whereNotIn('currency_id', $currenciesWithAllowCredit)->delete();
                }
            }

            // Handle credit limits (array format - aligned with supplier)
            if ($request->has('credit_limits') && is_array($request->input('credit_limits'))) {
                foreach ($request->input('credit_limits') as $creditLimitData) {
                    $currencyId = $creditLimitData['currency_id'] ?? null;
                    if ($currencyId && $customer->hasAllowCreditForCurrency($currencyId)) {
                        try {
                            $customer->setCreditLimit($currencyId, $creditLimitData['credit_limit']);
                        } catch (\Exception $e) {
                            throw new \Exception('Credit limit validation failed: '.$e->getMessage());
                        }
                    }
                }
            }

            // Handle cheque limits (array format - aligned with supplier)
            if ($request->has('cheque_limits') && is_array($request->input('cheque_limits'))) {
                $openingBalanceCurrencyIds = collect($request->input('opening_balances', []))
                    ->map(function ($ob) {
                        $code = $ob['currency'] ?? null;
                        if (! $code) {
                            return;
                        }

                        return \App\Models\Currency::where('code', $code)->first()?->id;
                    })
                    ->filter()
                    ->unique()
                    ->values()
                    ->toArray();

                foreach ($request->input('cheque_limits') as $chequeLimitData) {
                    $maxCheques = $chequeLimitData['max_cheques'] ?? null;
                    if (empty($maxCheques) || $maxCheques === '' || $maxCheques === null) {
                        continue;
                    }

                    $currencyId = $chequeLimitData['currency_id'] ?? null;
                    if ($currencyId && in_array($currencyId, $openingBalanceCurrencyIds)) {
                        try {
                            \App\Models\CustomerChequeLimit::create([
                                'customer_id' => $customer->id,
                                'currency_id' => $currencyId,
                                'max_cheques' => $maxCheques,
                                'used_cheques' => 0,
                                'available_cheques' => $maxCheques,
                                'notes' => $chequeLimitData['notes'] ?? null,
                                'is_active' => true,
                            ]);
                        } catch (\Exception $e) {
                            throw new \Exception('Cheque limit validation failed: '.$e->getMessage());
                        }
                    }
                }
            }

            // Handle contacts
            if ($request->has('contacts')) {
                foreach ($request->input('contacts') as $contactData) {
                    $isPrimary = isset($contactData['is_primary']) && (bool) $contactData['is_primary'];

                    $contact = \App\Models\CustomerContact::create([
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

                    // Set as primary contact if specified (also updates customer.contacts_id)
                    if ($isPrimary) {
                        $customer->setPrimaryContact($contact->id);
                    }
                }
            }

            // Handle associations (many-to-many) - use attach on create (no existing to preserve)
            if ($request->has('associations')) {
                $associationIds = array_values(array_unique(array_filter((array) $request->input('associations'), fn ($id) => $id !== null && $id !== '')));
                if (! empty($associationIds)) {
                    $customer->associations()->attach($associationIds);
                }
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
                    'primaryContact',
                    'contacts',
                    'attachments',
                    'creditLimits.currency',
                    'chequeLimits.currency',
                    'openingBalances.currency',
                    'openingBalances.paymentTerm:id,code,name,nb_days',
                    'openingBalances.paymentMethod:id,code,name',
                ]),
            ]);
        });
    }

    /**
     * Upload attachments for a customer (dedicated endpoint; use after create/update without files).
     */
    public function uploadAttachments(UploadCustomerAttachmentsRequest $request, Customer $customer)
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
                "tenants/{$tenantId}/customers/{$customer->id}/attachments",
                $file
            );
            $meta = $metadata[$index] ?? [];
            $attachment = CustomerAttachment::create([
                'customer_id' => $customer->id,
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
        $cacheKey = "tenant_{$tenantId}_customer_names";
        app('cache')->store('database')->forget($cacheKey);

        return response()->json([
            'status' => true,
            'message' => 'Attachments uploaded successfully.',
            'data' => $created,
        ], 201);
    }

    /**
     * Show customer. Use ?section=full|attachments|appointments|visits|for_invoice (default: full).
     */
    public function show(Request $request, Customer $customer)
    {
        $section = $request->query('section', 'full');
        if (! in_array($section, self::SHOW_SECTIONS, true)) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid section. Allowed: '.implode(', ', self::SHOW_SECTIONS).'.',
            ], 422);
        }

        return match ($section) {
            'attachments' => app(GetCustomerAttachmentsAction::class)->execute($customer),
            'appointments' => app(GetCustomerAppointmentHistoryAction::class)->execute($customer->id),
            'visits' => app(GetCustomerVisitHistoryAction::class)->execute($customer->id),
            'for_invoice' => app(GetCustomerForInvoiceAction::class)->execute($customer->id),
            default => app(ShowCustomerFullAction::class)->execute($customer),
        };
    }

    /**
     * Delete a customer attachment.
     */
    public function deleteAttachment(Customer $customer, CustomerAttachment $attachment)
    {
        if ($attachment->customer_id !== (int) $customer->id) {
            return response()->json([
                'status' => false,
                'message' => 'Attachment does not belong to this customer.',
            ], 403);
        }
        $filePath = str_replace(url('storage/'), '', $attachment->file_path);
        $filePath = str_replace(url('/storage/'), '', $filePath);
        if (Storage::disk('public')->exists($filePath)) {
            Storage::disk('public')->delete($filePath);
        }
        $attachment->delete();
        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_customer_names");

        return response()->json([
            'status' => true,
            'message' => 'Attachment deleted successfully.',
        ]);
    }

    public function update(UpdateCustomerRequest $request, Customer $customer)
    {
        $validated = $request->validated();
        logger()->info('Validated data', $validated);

        // Use database transaction to ensure all operations succeed or fail together
        return DB::transaction(function () use ($request, $validated, $customer) {

            // Handle billing address - update/create when any field filled; delete when all empty
            if ($this->hasAnyBillingField($request)) {
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
                    $existingBillingPivot->update($billingAddressData);
                    $customer->addresses()->updateExistingPivot($existingBillingPivot->id, $billingPivotData);
                } else {
                    $billingAddress = Address::create($billingAddressData);
                    $customer->addresses()->attach($billingAddress->id, $billingPivotData);
                }
            } else {
                // All billing fields empty - remove billing address from database
                $billingAddresses = $customer->billingAddresses()->get();
                foreach ($billingAddresses as $address) {
                    $customer->addresses()->detach($address->id);
                    $address->delete();
                }
            }

            // Handle shipping addresses - update existing or create new
            if ($request->has('shipping_addresses')) {
                $shippingAddresses = $request->input('shipping_addresses');
                $existingShippingPivots = $customer->shippingAddresses()->get()->keyBy('id');
                $newShippingIds = [];

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
                        'is_primary' => $index === 0,
                        'address_name' => $index === 0 ? 'Primary Shipping Address' : 'Shipping Address '.($index + 1),
                        'notes' => $shippingAddressData['notes'] ?? null,
                    ];

                    if (isset($shippingAddressData['id']) && $existingShippingPivots->has($shippingAddressData['id'])) {
                        $existingShipping = $existingShippingPivots->get($shippingAddressData['id']);
                        $existingShipping->update($shippingAddressDataForTable);
                        $customer->addresses()->updateExistingPivot($existingShipping->id, $shippingPivotData);
                        $newShippingIds[] = $existingShipping->id;
                    } else {
                        $newAddress = Address::create($shippingAddressDataForTable);
                        $customer->addresses()->attach($newAddress->id, $shippingPivotData);
                        $newShippingIds[] = $newAddress->id;
                    }
                }

                $addressesToDelete = $existingShippingPivots->keys()->diff($newShippingIds);
                foreach ($addressesToDelete as $addressId) {
                    $customer->addresses()->detach($addressId);
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
                    $address->delete();
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

            // Payment terms are per-currency in opening_balances, not on customer - remove from validated
            unset($validated['payment_term_id'], $validated['payment_method_id'], $validated['allow_credit']);

            // Convert empty date strings to null for database compatibility
            $dateFields = ['taxed_from_date', 'taxed_till_date', 'exempted_from_date', 'exempted_till_date'];
            foreach ($dateFields as $field) {
                if (isset($validated[$field]) && $validated[$field] === '') {
                    $validated[$field] = null;
                }
            }

            $customer->update($validated);

            // Handle opening balances FIRST (required before credit/cheque limits) — stable IDs: update existing by id, create new, delete removed only
            if ($request->has('opening_balances')) {
                $existingIds = $customer->openingBalances()->pluck('id')->toArray();
                $requestIds = collect($request->input('opening_balances'))
                    ->pluck('id')
                    ->filter(fn ($id) => $id !== null && $id !== '')
                    ->map(fn ($id) => (int) $id)
                    ->unique()
                    ->values()
                    ->toArray();
                $idsToDelete = array_diff($existingIds, $requestIds);
                if (count($idsToDelete) > 0) {
                    $customer->openingBalances()->whereIn('id', $idsToDelete)->delete();
                }

                foreach ($request->input('opening_balances') as $openingBalanceData) {
                    $currencyId = $openingBalanceData['currency_id'] ?? null;
                    if (! $currencyId && isset($openingBalanceData['currency'])) {
                        if (is_numeric($openingBalanceData['currency'])) {
                            $currencyId = (int) $openingBalanceData['currency'];
                        } else {
                            $currency = \App\Models\Currency::where('code', $openingBalanceData['currency'])->first();
                            $currencyId = $currency?->id;
                        }
                    }

                    if (! $currencyId) {
                        continue;
                    }

                    $amount = $openingBalanceData['opening_amount'] ?? ($openingBalanceData['amount'] ?? null);
                    $date = $openingBalanceData['opening_date'] ?? ($openingBalanceData['date'] ?? null);
                    $notes = $openingBalanceData['notes'] ?? null;
                    $id = isset($openingBalanceData['id']) && $openingBalanceData['id'] !== '' && $openingBalanceData['id'] !== null
                        ? (int) $openingBalanceData['id']
                        : null;

                    $existing = $id ? $customer->openingBalances()->where('id', $id)->first() : null;
                    $rowId = $existing ? $id : null;

                    $this->openingBalanceService->setCustomerOpeningBalance(
                        $customer,
                        $currencyId,
                        $amount,
                        $date,
                        $notes,
                        $openingBalanceData['payment_term_id'] ?? null,
                        $openingBalanceData['payment_method_id'] ?? null,
                        (bool) ($openingBalanceData['allow_credit'] ?? false),
                        $openingBalanceData['payment_day'] ?? null,
                        $openingBalanceData['track_payment'] ?? 'no',
                        $openingBalanceData['settlement_method'] ?? null,
                        (bool) ($openingBalanceData['accept_cheques'] ?? false),
                        $rowId
                    );
                }

                $customer->load('openingBalances');
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
                    $customer->creditLimits()->delete();
                } else {
                    $customer->creditLimits()->whereNotIn('currency_id', $currenciesWithAllowCredit)->delete();
                }
            }

            // Handle credit limits (array format - aligned with supplier)
            if ($request->has('credit_limits') && is_array($request->input('credit_limits'))) {
                foreach ($request->input('credit_limits') as $creditLimitData) {
                    $currencyId = $creditLimitData['currency_id'] ?? null;
                    if ($currencyId && $customer->hasAllowCreditForCurrency($currencyId)) {
                        try {
                            $customer->setCreditLimit($currencyId, $creditLimitData['credit_limit']);
                        } catch (\Exception $e) {
                            throw new \Exception('Credit limit validation failed: '.$e->getMessage());
                        }
                    }
                }
            }

            // Handle cheque limits (array format - aligned with supplier) - update existing or create new
            if ($request->has('cheque_limits') && is_array($request->input('cheque_limits'))) {
                $openingBalanceCurrencyIds = collect($request->input('opening_balances', []))
                    ->map(function ($ob) {
                        $code = $ob['currency'] ?? null;
                        if (! $code) {
                            return;
                        }

                        return \App\Models\Currency::where('code', $code)->first()?->id;
                    })
                    ->filter()
                    ->unique()
                    ->values()
                    ->toArray();

                $existingChequeLimits = $customer->chequeLimits()->get()->keyBy('currency_id');
                $incomingCurrencyIds = [];

                foreach ($request->input('cheque_limits') as $chequeLimitData) {
                    $maxCheques = $chequeLimitData['max_cheques'] ?? null;
                    if (empty($maxCheques) || $maxCheques === '' || $maxCheques === null) {
                        continue;
                    }

                    $currencyId = $chequeLimitData['currency_id'] ?? null;
                    if ($currencyId && in_array($currencyId, $openingBalanceCurrencyIds)) {
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
                                \App\Models\CustomerChequeLimit::create([
                                    'customer_id' => $customer->id,
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

                $customer->chequeLimits()->whereNotIn('currency_id', $incomingCurrencyIds)->delete();
            } else {
                $customer->chequeLimits()->delete();
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
                        $contact = \App\Models\CustomerContact::create([
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

            // Handle associations (many-to-many) - preserve pivot IDs: only detach removed, attach new
            if ($request->has('associations')) {
                $requestIds = array_values(array_unique(array_filter((array) $request->input('associations'), fn ($id) => $id !== null && $id !== '')));
                $currentIds = $customer->associations()->pluck('associations.id')->toArray();
                $toDetach = array_diff($currentIds, $requestIds);
                $toAttach = array_diff($requestIds, $currentIds);
                if (! empty($toDetach)) {
                    $customer->associations()->detach($toDetach);
                }
                if (! empty($toAttach)) {
                    $customer->associations()->attach($toAttach);
                }
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
                'primaryContact',
                'contacts',
                'attachments',
                'creditLimits.currency',
                'chequeLimits.currency',
                'openingBalances.currency',
                'openingBalances.paymentTerm:id,code,name,nb_days',
                'openingBalances.paymentMethod:id,code,name',
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
}
