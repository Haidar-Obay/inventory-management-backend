<?php

declare(strict_types=1);

namespace App\Actions\Supplier;

use App\Http\Resources\Supplier\SupplierNameResource;
use App\Models\Supplier;

class GetSupplierNamesAction
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function execute(): array
    {
        $suppliers = Supplier::select('id', 'first_name', 'middle_name', 'last_name', 'company_name', 'display_name', 'phone1')
            ->where('active', true)
            ->get()
            ->map(function ($supplier) {
                return [
                    'id' => $supplier->id,
                    'name' => $supplier->display_name ?: $supplier->company_name ?: $supplier->getFullNameAttribute(),
                    'phone' => $supplier->phone1 ?? '',
                ];
            });

        return SupplierNameResource::collection($suppliers)->resolve();
    }
}
