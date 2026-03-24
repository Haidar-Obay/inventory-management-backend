<?php

declare(strict_types=1);

namespace App\Http\Resources\Supplier;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupplierItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $item = is_array($this->resource) ? $this->resource : (array) $this->resource;

        return [
            'id' => $item['id'] ?? null,
            'code' => $item['code'] ?? null,
            'name' => $item['name'] ?? null,
            'purchase_description' => $item['purchase_description'] ?? null,
            'supplier_cost' => $item['supplier_cost'] ?? null,
            'supplier_currency' => $item['supplier_currency'] ?? null,
            'supplier_currency_id' => $item['supplier_currency_id'] ?? null,
            'tax_group' => $item['tax_group'] ?? null,
            'purchase_uom' => $item['purchase_uom'] ?? null,
        ];
    }
}
