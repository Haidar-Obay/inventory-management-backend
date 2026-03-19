<?php

declare(strict_types=1);

namespace App\Actions\Customer;

use App\Http\Resources\Customer\CustomerBalanceResource;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GetCustomerBalanceAction
{
    public function execute(Request $request): JsonResponse
    {
        $currencyId = $request->query('currency_id') ? (int) $request->query('currency_id') : null;
        $activeOnly = $request->boolean('active_only');
        $from = $request->query('from');
        $to = $request->query('to');
        $groupByCustomer = $request->boolean('group_by_customer');

        $customers = Customer::select(['id', 'first_name', 'middle_name', 'last_name', 'phone1', 'active'])
            ->with([
                'openingBalances' => function ($q) use ($currencyId, $from, $to) {
                    $q->where('is_active', true);
                    if ($currencyId !== null) {
                        $q->where('currency_id', $currencyId);
                    }
                    if ($from !== null && $from !== '') {
                        $q->whereDate('opening_date', '>=', $from);
                    }
                    if ($to !== null && $to !== '') {
                        $q->whereDate('opening_date', '<=', $to);
                    }
                },
                'openingBalances.currency:id,code,name',
                'openingBalances.paymentTerm:id,name',
                'primaryBillingAddress:id,address_line1',
            ])
            ->when($activeOnly, fn ($q) => $q->where('active', true))
            ->orderBy('id')
            ->get();

        $rows = [];
        foreach ($customers as $customer) {
            $name = trim(implode(' ', array_filter([
                $customer->first_name,
                $customer->middle_name,
                $customer->last_name,
            ])));
            $address = $customer->primaryBillingAddress->first()?->address_line1 ?? '';

            if ($customer->openingBalances->isEmpty()) {
                if ($currencyId === null && ! $groupByCustomer) {
                    $rows[] = [
                        'row_id' => 'c'.$customer->id.'-0',
                        'id' => $customer->id,
                        'customer_name' => $name,
                        'address' => $address,
                        'phone1' => $customer->phone1 ?? '',
                        'currency' => '',
                        'payment_terms' => '',
                        'balance' => 0,
                        'active' => (bool) $customer->active,
                    ];
                }

                continue;
            }

            if ($groupByCustomer) {
                $currencies = [];
                $paymentTerms = [];
                $balances = [];
                foreach ($customer->openingBalances as $ob) {
                    $currencies[] = $ob->currency?->code ?? $ob->currency?->name ?? '';
                    $paymentTerms[] = $ob->paymentTerm?->name ?? '';
                    $balances[] = number_format((float) $ob->opening_amount, 2);
                }
                $rows[] = [
                    'row_id' => 'c'.$customer->id.'-0',
                    'id' => $customer->id,
                    'customer_name' => $name,
                    'address' => $address,
                    'phone1' => $customer->phone1 ?? '',
                    'currency' => implode(' - ', $currencies),
                    'payment_terms' => implode(' / ', $paymentTerms),
                    'balance' => implode(' / ', $balances),
                    'active' => (bool) $customer->active,
                ];

                continue;
            }

            foreach ($customer->openingBalances as $ob) {
                $rows[] = [
                    'row_id' => 'c'.$customer->id.'-'.$ob->id,
                    'id' => $customer->id,
                    'customer_name' => $name,
                    'address' => $address,
                    'phone1' => $customer->phone1 ?? '',
                    'currency' => $ob->currency?->code ?? $ob->currency?->name ?? '',
                    'payment_terms' => $ob->paymentTerm?->name ?? '',
                    'balance' => (float) $ob->opening_amount,
                    'active' => (bool) $customer->active,
                ];
            }
        }

        return response()->json([
            'status' => true,
            'message' => 'Customer balances fetched successfully.',
            'data' => CustomerBalanceResource::collection($rows)->resolve(),
        ]);
    }
}
