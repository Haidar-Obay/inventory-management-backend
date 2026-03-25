<?php

declare(strict_types=1);

namespace App\Actions\Supplier;

use App\Http\Resources\Supplier\SupplierBriefResource;
use App\Models\Supplier;

class GetSupplierBriefAction
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function execute(): array
    {
        $suppliers = Supplier::select('id', 'display_name', 'company_name', 'phone1', 'supplier_group_id')
            ->where('active', true)
            ->with(['supplierGroup:id,name'])
            ->orderBy('display_name')
            ->get()
            ->map(function ($supplier) {
                return [
                    'id' => $supplier->id,
                    'name' => $supplier->display_name ?: $supplier->company_name ?: '',
                    'company_name' => $supplier->company_name,
                    'phone1' => $supplier->phone1,
                    'supplier_group_name' => $supplier->supplierGroup ? $supplier->supplierGroup->name : null,
                    'supplier_group' => $supplier->supplierGroup ? [
                        'name' => $supplier->supplierGroup->name,
                    ] : null,
                ];
            });

        return SupplierBriefResource::collection($suppliers)->resolve();
    }
}
