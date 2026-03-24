<?php

declare(strict_types=1);

namespace App\Http\Resources\Supplier;

use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Supplier
 */
class SupplierForPurchaseInvoiceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Supplier $supplier */
        $supplier = $this->resource;

        $supplier->loadMissing([
            'openingBalances.paymentTerm:id,code,name,nb_days,active',
        ]);

        $openingBalances = $supplier->openingBalances()
            ->where('is_active', true)
            ->with([
                'currency:id,code,name,iso_code',
                'paymentTerm:id,code,name,nb_days,active',
            ])
            ->get();

        $openingBalancesData = $openingBalances->map(function ($balance) {
            $currency = $balance->currency;
            $paymentTerm = $balance->paymentTerm;

            return [
                'id' => $balance->id,
                'currency_id' => $balance->currency_id,
                'currency_code' => $currency->code ?? null,
                'currency_name' => $currency->name ?? null,
                'currency_iso_code' => $currency->iso_code ?? null,
                'is_primary' => $currency ? $currency->isPrimary() : false,
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
        });

        $firstOb = $supplier->openingBalances->first();

        return [
            'id' => $supplier->id,
            'display_name' => $supplier->display_name,
            'company_name' => $supplier->company_name,
            'phone1' => $supplier->phone1,
            'invoicing_mode' => $supplier->invoicing_mode,
            'payment_term' => $firstOb?->paymentTerm ? [
                'id' => $firstOb->paymentTerm->id,
                'code' => $firstOb->paymentTerm->code,
                'name' => $firstOb->paymentTerm->name,
                'nb_days' => $firstOb->paymentTerm->nb_days,
                'active' => $firstOb->paymentTerm->active,
            ] : null,
            'opening_balances' => $openingBalancesData,
        ];
    }
}
