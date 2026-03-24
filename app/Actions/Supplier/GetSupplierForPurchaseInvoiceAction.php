<?php

declare(strict_types=1);

namespace App\Actions\Supplier;

use App\Http\Resources\Supplier\SupplierForPurchaseInvoiceResource;
use App\Models\Supplier;

class GetSupplierForPurchaseInvoiceAction
{
    /**
     * @return array<string, mixed>
     */
    public function execute(Supplier $supplier): array
    {
        return (new SupplierForPurchaseInvoiceResource($supplier))->toArray(request());
    }
}
