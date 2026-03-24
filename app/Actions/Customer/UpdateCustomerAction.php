<?php

declare(strict_types=1);

namespace App\Actions\Customer;

use App\Http\Requests\Customer\UpdateCustomerRequest;
use App\Models\Customer;
use App\Models\CustomerAttachment;
use App\Models\CustomerChequeLimit;
use App\Models\CustomerContact;
use App\Services\AddressSyncService;
use App\Services\CurrencyResolverService;
use App\Services\OpeningBalanceService;
use App\Services\SharedAttachmentService;
use App\Services\SharedContactService;

class UpdateCustomerAction
{
    public function __construct(
        protected OpeningBalanceService $openingBalanceService,
        protected CurrencyResolverService $currencyResolverService,
        protected AddressSyncService $addressSyncService,
        protected SharedContactService $sharedContactService,
        protected SharedAttachmentService $sharedAttachmentService
    ) {}

    public function execute(UpdateCustomerRequest $request, Customer $customer): Customer
    {
        $customerRoute = $request->route('customer');
        $resolvedCustomerId = (int) ($customer->getKey()
            ?? (is_object($customerRoute) ? $customerRoute->id : $customerRoute)
            ?? 0);

        $validated = $request->validated();

        $this->addressSyncService->syncBillingAddress(
            $customer,
            $request,
            $this->addressSyncService->hasAnyBillingField($request),
            false
        );

        $this->addressSyncService->syncShippingAddresses(
            $customer,
            $request->has('shipping_addresses') ? (array) $request->input('shipping_addresses') : null,
            false
        );

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
                $currencyId = $this->currencyResolverService->resolveIdFromPayload($openingBalanceData);

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

        // Handle cheque limits (array format - aligned with supplier) - update existing or create new
        if ($request->has('cheque_limits') && is_array($request->input('cheque_limits'))) {
            $openingBalanceCurrencyIds = collect($request->input('opening_balances', []))
                ->map(function ($ob) {
                    return $this->currencyResolverService->resolveIdFromPayload($ob);
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
                            CustomerChequeLimit::create([
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
            $this->sharedContactService->syncContacts(
                $customer,
                (array) $request->input('contacts', []),
                CustomerContact::class,
                'customer_id',
                $request->filled('contacts_id') ? (int) $request->input('contacts_id') : null,
                $resolvedCustomerId
            );
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

        // Handle attachments
        $this->sharedAttachmentService->syncAttachments(
            $customer,
            $request,
            'customers',
            CustomerAttachment::class,
            'customer_id',
            $resolvedCustomerId,
            true,
            false
        );

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
