<?php

declare(strict_types=1);

namespace App\Actions\Supplier;

use App\Http\Resources\Supplier\SupplierBalanceResource;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GetSupplierBalanceAction
{
    public function execute(Request $request): JsonResponse
    {
        $currencyId = $request->query('currency_id') ? (int) $request->query('currency_id') : null;
        $activeOnly = $request->boolean('active_only');
        $from = $request->query('from');
        $to = $request->query('to');
        $groupBySupplier = $request->boolean('group_by_supplier');

        $suppliers = Supplier::select(['id', 'first_name', 'middle_name', 'last_name', 'company_name', 'display_name', 'phone1', 'active'])
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
        foreach ($suppliers as $supplier) {
            $name = trim($supplier->display_name ?? $supplier->company_name ?? implode(' ', array_filter([
                $supplier->first_name,
                $supplier->middle_name,
                $supplier->last_name,
            ])));
            $address = $supplier->primaryBillingAddress->first()?->address_line1 ?? '';

            if ($supplier->openingBalances->isEmpty()) {
                if ($currencyId === null && ! $groupBySupplier) {
                    $rows[] = [
                        'row_id' => 's'.$supplier->id.'-0',
                        'id' => $supplier->id,
                        'supplier_name' => $name,
                        'address' => $address,
                        'phone1' => $supplier->phone1 ?? '',
                        'currency' => '',
                        'payment_terms' => '',
                        'balance' => 0,
                        'active' => (bool) $supplier->active,
                    ];
                }

                continue;
            }

            if ($groupBySupplier) {
                $currencies = [];
                $paymentTerms = [];
                $balances = [];
                foreach ($supplier->openingBalances as $ob) {
                    $currencies[] = $ob->currency?->code ?? $ob->currency?->name ?? '';
                    $paymentTerms[] = $ob->paymentTerm?->name ?? '';
                    $balances[] = number_format((float) $ob->opening_amount, 2);
                }
                $rows[] = [
                    'row_id' => 's'.$supplier->id.'-0',
                    'id' => $supplier->id,
                    'supplier_name' => $name,
                    'address' => $address,
                    'phone1' => $supplier->phone1 ?? '',
                    'currency' => implode(' - ', $currencies),
                    'payment_terms' => implode(' / ', $paymentTerms),
                    'balance' => implode(' / ', $balances),
                    'active' => (bool) $supplier->active,
                ];

                continue;
            }

            foreach ($supplier->openingBalances as $ob) {
                $rows[] = [
                    'row_id' => 's'.$supplier->id.'-'.$ob->id,
                    'id' => $supplier->id,
                    'supplier_name' => $name,
                    'address' => $address,
                    'phone1' => $supplier->phone1 ?? '',
                    'currency' => $ob->currency?->code ?? $ob->currency?->name ?? '',
                    'payment_terms' => $ob->paymentTerm?->name ?? '',
                    'balance' => (float) $ob->opening_amount,
                    'active' => (bool) $supplier->active,
                ];
            }
        }

        return response()->json([
            'status' => true,
            'message' => 'Supplier balances fetched successfully.',
            'data' => SupplierBalanceResource::collection($rows)->resolve(),
        ]);
    }
}
