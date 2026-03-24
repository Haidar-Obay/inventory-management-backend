<?php

declare(strict_types=1);

namespace App\Http\Resources\Supplier;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupplierBriefResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $item = is_array($this->resource) ? $this->resource : (array) $this->resource;

        return [
            'id' => $item['id'] ?? null,
            'name' => $item['name'] ?? '',
            'company_name' => $item['company_name'] ?? null,
            'phone1' => $item['phone1'] ?? null,
            'supplier_group_name' => $item['supplier_group_name'] ?? null,
            'supplier_group' => $item['supplier_group'] ?? null,
        ];
    }
}
