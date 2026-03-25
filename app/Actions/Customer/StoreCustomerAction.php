<?php

declare(strict_types=1);

namespace App\Actions\Customer;

use App\Http\Requests\Customer\StoreCustomerRequest;
use App\Models\Customer;
use App\Models\CustomerAttachment;
use App\Models\CustomerChequeLimit;
use App\Models\CustomerContact;
use App\Services\AddressSyncService;
use App\Services\CurrencyResolverService;
use App\Services\OpeningBalanceService;
use App\Services\SharedAttachmentService;
use App\Services\SharedContactService;

class StoreCustomerAction
{
    public function __construct(
        protected OpeningBalanceService $openingBalanceService,
        protected CurrencyResolverService $currencyResolverService,
        protected AddressSyncService $addressSyncService,
        protected SharedContactService $sharedContactService,
        protected SharedAttachmentService $sharedAttachmentService
    ) {}

    public function execute(StoreCustomerRequest $request, int $nextId): Customer
    {
        $validated = $request->validated();

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
        $customer = new Customer($validated);
        $customer->id = $nextId;
        $customer->save();

        // Handle billing address - unified structure (after customer is created)
        if ($this->addressSyncService->hasAnyBillingField($request)) {
            $this->addressSyncService->createBillingAddress($customer, $request, false);
        }

        // Handle shipping addresses - unified structure as array (after customer is created)
        if ($request->has('shipping_addresses')) {
            $this->addressSyncService->createShippingAddresses(
                $customer,
                (array) $request->input('shipping_addresses'),
                false,
                false
            );
        }

        // Handle opening balances FIRST (required before credit/cheque limits)
        if ($request->has('opening_balances')) {
            foreach ($request->input('opening_balances') as $openingBalanceData) {
                $currencyId = $this->currencyResolverService->resolveId($openingBalanceData['currency'] ?? null);
                if ($currencyId) {
                    $this->openingBalanceService->setCustomerOpeningBalance(
                        $customer,
                        $currencyId,
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
                    return $this->currencyResolverService->resolveIdFromPayload($ob);
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
                    return $this->currencyResolverService->resolveIdFromPayload($ob);
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
                        CustomerChequeLimit::create([
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
            $this->sharedContactService->createContacts(
                $customer,
                (array) $request->input('contacts', []),
                CustomerContact::class,
                'customer_id',
                (int) $customer->id
            );
        }

        // Handle associations (many-to-many) - use attach on create (no existing to preserve)
        if ($request->has('associations')) {
            $associationIds = array_values(array_unique(array_filter((array) $request->input('associations'), fn ($id) => $id !== null && $id !== '')));
            if (! empty($associationIds)) {
                $customer->associations()->attach($associationIds);
            }
        }

        // Handle attachments
        if ($request->has('attachments')) {
            $this->sharedAttachmentService->createAttachments(
                $customer,
                $request,
                'customers',
                CustomerAttachment::class,
                'customer_id',
                (int) $customer->id
            );
        }

        // Clear customer names cache
        $tenantId = tenant('id');
        $cacheKey = "tenant_{$tenantId}_customer_names";
        app('cache')->store('database')->forget($cacheKey);

        return $customer->load([
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
    }
}
