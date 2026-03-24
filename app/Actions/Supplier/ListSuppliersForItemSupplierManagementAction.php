<?php

declare(strict_types=1);

namespace App\Actions\Supplier;

use App\Http\Resources\Supplier\SupplierItemManagementSupplierResource;
use App\Models\Supplier;

class ListSuppliersForItemSupplierManagementAction
{
    public function execute(): array
    {
        $mapped = Supplier::select('id', 'display_name', 'company_name', 'first_name', 'last_name')
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

        return SupplierItemManagementSupplierResource::collection($mapped)->resolve();
    }
}
