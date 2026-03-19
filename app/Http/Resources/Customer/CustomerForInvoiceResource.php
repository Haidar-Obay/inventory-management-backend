<?php

declare(strict_types=1);

namespace App\Http\Resources\Customer;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerForInvoiceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $customer = $this->resource;

        $phones = array_values(array_filter([
            $customer->phone1,
            $customer->phone2,
            $customer->phone3,
        ]));

        $billingAddresses = $customer->billingAddresses->map(function ($address) {
            $parts = array_filter([
                $address->address_line1,
                $address->address_line2,
                $address->building ? "Building: {$address->building}" : null,
                $address->floor ? "Floor: {$address->floor}" : null,
                $address->zip_code,
            ]);

            return [
                'id' => $address->id,
                'formatted' => implode(', ', $parts),
                'address_line1' => $address->address_line1,
                'address_line2' => $address->address_line2,
            ];
        });

        $shippingAddresses = $customer->shippingAddresses->map(function ($address) {
            $parts = array_filter([
                $address->address_line1,
                $address->address_line2,
                $address->building ? "Building: {$address->building}" : null,
                $address->floor ? "Floor: {$address->floor}" : null,
                $address->zip_code,
            ]);

            return [
                'id' => $address->id,
                'formatted' => implode(', ', $parts),
                'address_line1' => $address->address_line1,
                'address_line2' => $address->address_line2,
            ];
        });

        $openingBalancesData = $customer->openingBalances->map(function ($balance) {
            $currency = $balance->currency;
            $paymentTerm = $balance->paymentTerm;

            return [
                'id' => $balance->id,
                'currency_id' => $balance->currency_id,
                'currency_code' => $currency->code ?? null,
                'currency_name' => $currency->name ?? null,
                'currency_iso_code' => $currency->iso_code ?? null,
                'is_primary' => $currency->isPrimary(),
                'opening_amount' => $balance->opening_amount,
                'opening_date' => $balance->opening_date,
                'notes' => $balance->notes,
                'payment_day' => $balance->payment_day,
                'track_payment' => $balance->track_payment,
                'settlement_method' => $balance->settlement_method,
                'accept_cheques' => (bool) $balance->accept_cheques,
                'is_active' => $balance->is_active,
                'payment_term_id' => $balance->payment_term_id,
                'payment_term' => $paymentTerm ? [
                    'id' => $paymentTerm->id,
                    'code' => $paymentTerm->code,
                    'name' => $paymentTerm->name,
                    'nb_days' => $paymentTerm->nb_days,
                ] : null,
            ];
        })->values();

        return [
            'id' => $customer->id,
            'first_name' => $customer->first_name,
            'middle_name' => $customer->middle_name,
            'last_name' => $customer->last_name,
            'display_name' => $customer->display_name,
            'company_name' => $customer->company_name,
            'phones' => $phones,
            'one_time_account' => $customer->one_time_account,
            'salesman_id' => $customer->salesman_id,
            'salesman' => $customer->salesman ? [
                'id' => $customer->salesman->id,
                'name' => $customer->salesman->name,
            ] : null,
            'payment_term' => $customer->openingBalances->first()?->paymentTerm ? [
                'id' => $customer->openingBalances->first()->paymentTerm->id,
                'name' => $customer->openingBalances->first()->paymentTerm->name,
                'code' => $customer->openingBalances->first()->paymentTerm->code,
                'nb_days' => $customer->openingBalances->first()->paymentTerm->nb_days,
            ] : null,
            'billing_addresses' => $billingAddresses,
            'shipping_addresses' => $shippingAddresses,
            'opening_balances' => $openingBalancesData,
        ];
    }
}
