<?php

declare(strict_types=1);

namespace App\Http\Resources\Supplier;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupplierBalanceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $item = is_array($this->resource) ? $this->resource : (array) $this->resource;

        return [
            'row_id' => $item['row_id'] ?? null,
            'id' => $item['id'] ?? null,
            'supplier_name' => $item['supplier_name'] ?? '',
            'address' => $item['address'] ?? '',
            'phone1' => $item['phone1'] ?? '',
            'currency' => $item['currency'] ?? '',
            'payment_terms' => $item['payment_terms'] ?? '',
            'balance' => $item['balance'] ?? 0,
            'active' => (bool) ($item['active'] ?? false),
        ];
    }
}
