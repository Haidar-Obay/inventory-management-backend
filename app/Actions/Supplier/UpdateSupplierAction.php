<?php

declare(strict_types=1);

namespace App\Actions\Supplier;

use App\Http\Requests\Supplier\UpdateSupplierRequest;
use App\Models\Supplier;
use App\Models\SupplierAttachment;
use App\Models\SupplierChequeLimit;
use App\Models\SupplierContact;
use App\Services\AddressSyncService;
use App\Services\CurrencyResolverService;
use App\Services\OpeningBalanceService;
use App\Services\SharedAttachmentService;
use App\Services\SharedContactService;

class UpdateSupplierAction
{
    public function __construct(
        protected OpeningBalanceService $openingBalanceService,
        protected CurrencyResolverService $currencyResolverService,
        protected AddressSyncService $addressSyncService,
        protected SharedContactService $sharedContactService,
        protected SharedAttachmentService $sharedAttachmentService
    ) {}

    public function execute(UpdateSupplierRequest $request, Supplier $supplier): Supplier
    {
        $supplierRoute = $request->route('supplier');
        $resolvedSupplierId = (int) ($supplier->getKey()
            ?? (is_object($supplierRoute) ? $supplierRoute->id : $supplierRoute)
            ?? 0);

        // bar_code is the canonical input
        $validated = $request->validated();
        // Payment terms are per-currency in opening_balances, not on supplier
        unset($validated['payment_term_id'], $validated['payment_method_id'], $validated['allow_credit'], $validated['accept_cheques']);

        // Update the supplier
        $supplier->update($validated);

        // Handle addresses
        $this->addressSyncService->syncBillingAddress(
            $supplier,
            $request,
            $this->addressSyncService->hasAnyBillingField($request),
            false
        );
        $this->addressSyncService->syncShippingAddresses(
            $supplier,
            $request->has('shipping_addresses') ? (array) $request->input('shipping_addresses') : null,
            true
        );

        // Handle contacts
        if ($request->has('contacts')) {
            $this->sharedContactService->syncContacts(
                $supplier,
                (array) $request->input('contacts', []),
                SupplierContact::class,
                'supplier_id',
                $request->filled('contacts_id') ? (int) $request->input('contacts_id') : null,
                $resolvedSupplierId
            );
        }

        // Always try to call updateAttachments - it will handle the logic internally
        $this->sharedAttachmentService->syncAttachments(
            $supplier,
            $request,
            'suppliers',
            SupplierAttachment::class,
            'supplier_id',
            $resolvedSupplierId,
            true,
            true,
            'Supplier updateAttachments: Error creating attachment'
        );

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
                            SupplierChequeLimit::create([
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
                    return $this->currencyResolverService->resolveIdFromPayload($ob);
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

        return $supplier;
    }
}

