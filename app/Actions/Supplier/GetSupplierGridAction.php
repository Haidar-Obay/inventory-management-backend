<?php

declare(strict_types=1);

namespace App\Actions\Supplier;

use App\Models\Supplier;

class GetSupplierGridAction
{
    public function execute(): array
    {
        $suppliers = Supplier::with(['supplierGroup:id,name,code', 'openingBalances.currency:id,code,name']);

        // Get the suppliers data
        $suppliersData = $suppliers->get();

        // Transform the response to include only essential fields
        return $suppliersData->map(function ($supplier) {
            return [
                'id' => $supplier->id,
                'title' => $supplier->title,
                'first_name' => $supplier->first_name,
                'middle_name' => $supplier->middle_name,
                'last_name' => $supplier->last_name,
                'display_name' => $supplier->display_name,
                'company_name' => $supplier->company_name,
                'phone1' => $supplier->phone1,
                'phone2' => $supplier->phone2,
                'phone3' => $supplier->phone3,
                'file_number' => $supplier->file_number,
                'bar_code' => $supplier->bar_code,
                'search_terms' => $supplier->search_terms,
                'indicator' => $supplier->indicator,
                'invoicing_mode' => $supplier->invoicing_mode,
                'active' => $supplier->active,
                'notes' => $supplier->notes,
                'is_foreign' => $supplier->is_foreign,
                'supplier_group' => $supplier->supplierGroup ? [
                    'id' => $supplier->supplierGroup->id,
                    'name' => $supplier->supplierGroup->name,
                    'code' => $supplier->supplierGroup->code,
                ] : null,
                'opening_balances' => $supplier->openingBalances->map(function ($openingBalance) {
                    return [
                        'id' => $openingBalance->id,
                        'currency_id' => $openingBalance->currency_id,
                        'currency_code' => $openingBalance->currency->code,
                        'currency_name' => $openingBalance->currency->name,
                        'opening_amount' => $openingBalance->opening_amount,
                        'opening_date' => $openingBalance->opening_date,
                        'notes' => $openingBalance->notes,
                        'is_active' => $openingBalance->is_active,
                    ];
                }),
                'created_at' => $supplier->created_at,
                'updated_at' => $supplier->updated_at,
            ];
        })->all();
    }
}
