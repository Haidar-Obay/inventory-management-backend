<?php

declare(strict_types=1);

namespace App\Actions\Supplier;

use App\Http\Requests\Supplier\StoreSupplierRequest;
use App\Models\Supplier;
use App\Models\SupplierAttachment;
use App\Models\SupplierChequeLimit;
use App\Models\SupplierContact;
use App\Services\AddressSyncService;
use App\Services\OpeningBalanceService;
use App\Services\SharedAttachmentService;
use App\Services\SharedContactService;

class StoreSupplierAction
{
    public function __construct(
        protected OpeningBalanceService $openingBalanceService,
        protected AddressSyncService $addressSyncService,
        protected SharedContactService $sharedContactService,
        protected SharedAttachmentService $sharedAttachmentService
    ) {}

    public function execute(StoreSupplierRequest $request, int $nextId): Supplier
    {
        // bar_code is the canonical input
        $validated = $request->validated();
        // Payment terms are per-currency in opening_balances, not on supplier
        unset($validated['payment_term_id'], $validated['payment_method_id'], $validated['allow_credit'], $validated['accept_cheques']);

        // Create the supplier with explicit sequential ID
        $supplier = new Supplier($validated);
        $supplier->id = $nextId;
        $supplier->save();

        // Handle addresses
        if ($this->addressSyncService->hasAnyBillingField($request)) {
            $this->addressSyncService->createBillingAddress($supplier, $request, true);
        }

        if ($request->has('shipping_addresses')) {
            $this->addressSyncService->createShippingAddresses(
                $supplier,
                (array) $request->input('shipping_addresses'),
                true,
                true
            );
        }

        // Handle contacts
        if ($request->has('contacts')) {
            $this->sharedContactService->createContacts(
                $supplier,
                (array) $request->input('contacts', []),
                SupplierContact::class,
                'supplier_id',
                (int) $supplier->id
            );
        }

        // Handle attachments
        if ($request->has('attachments')) {
            $this->sharedAttachmentService->createAttachments(
                $supplier,
                $request,
                'suppliers',
                SupplierAttachment::class,
                'supplier_id',
                (int) $supplier->id
            );
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
                        SupplierChequeLimit::create([
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

        return $supplier;
    }
}
