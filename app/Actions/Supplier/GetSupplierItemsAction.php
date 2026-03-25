<?php

declare(strict_types=1);

namespace App\Actions\Supplier;

use App\Http\Resources\Supplier\SupplierItemResource;
use App\Models\ItemBarcode;
use App\Models\Supplier;
use App\Services\CurrencyResolverService;

class GetSupplierItemsAction
{
    public function __construct(
        protected CurrencyResolverService $currencyResolverService
    ) {}

    public function execute(Supplier $supplier): array
    {
        // Get items related to this supplier with pivot data (cost)
        $items = $supplier->items()
            ->where('items.active', true) // Only active items
            ->select([
                'items.id',
                'items.code',
                'items.name',
                'items.purchase_description',
                'items.purchase_uom_id',
                'items.tax_group_id',
            ])
            ->withPivot(['cost', 'original_code', 'currency', 'is_primary'])
            ->with([
                // Load purchase UOM
                'purchaseUom:id,name',
                // Load tax group
                'taxGroup:id,code,name,value',
                // Load all UOMs with pivot data (we'll filter to purchase UOM in PHP)
                'unitOfMeasurements' => function ($query) {
                    $query->select([
                        'unit_of_measurements.id',
                        'unit_of_measurements.name',
                    ])
                        ->withPivot([
                            'id',
                            'operation',
                            'conversion',
                            'price_1',
                            'net_weight',
                            'net_volume',
                        ]);
                },
            ])
            ->orderBy('items.code')
            ->get();

        // Get all item UOM pivot IDs for batch barcode loading
        $itemUomPivotIds = [];
        foreach ($items as $item) {
            foreach ($item->unitOfMeasurements as $uom) {
                if ($uom->pivot && $uom->pivot->id) {
                    $itemUomPivotIds[] = $uom->pivot->id;
                }
            }
        }

        // Batch load barcodes
        $barcodesByPivotId = [];
        if (! empty($itemUomPivotIds)) {
            $barcodes = ItemBarcode::whereIn('item_unit_of_measurement_id', $itemUomPivotIds)
                ->select('item_unit_of_measurement_id', 'barcode')
                ->get()
                ->groupBy('item_unit_of_measurement_id');

            foreach ($barcodes as $pivotId => $barcodeGroup) {
                $barcodesByPivotId[$pivotId] = $barcodeGroup->pluck('barcode')->toArray();
            }
        }

        // Transform items to include purchase UOM data
        $mapped = $items->map(function ($item) use ($barcodesByPivotId) {
            // Get purchase UOM, or use first available UOM if purchase UOM doesn't exist
            $purchaseUom = $item->purchaseUom;
            $uomToUse = $purchaseUom;

            // If no purchase UOM, try to use the first available UOM
            if (! $uomToUse && $item->unitOfMeasurements->isNotEmpty()) {
                $uomToUse = $item->unitOfMeasurements->first();
            }

            // Get UOM pivot data (from item_unit_of_measurement)
            $uomPivot = null;
            if ($uomToUse) {
                $uomPivot = $item->unitOfMeasurements->firstWhere('id', $uomToUse->id);
            }

            // Get supplier cost from pivot
            $supplierCost = $item->pivot->cost ?? null;
            $supplierCurrency = $item->pivot->currency ?? null;

            // Look up currency ID from currency code
            $currencyId = $this->currencyResolverService->resolveId($supplierCurrency);

            // Get barcodes for UOM
            $barcodes = [];
            if ($uomPivot && $uomPivot->pivot && $uomPivot->pivot->id) {
                $pivotId = $uomPivot->pivot->id;
                $barcodes = $barcodesByPivotId[$pivotId] ?? [];
            }

            return [
                'id' => $item->id,
                'code' => $item->code,
                'name' => $item->name,
                'purchase_description' => $item->purchase_description,
                'supplier_cost' => $supplierCost ? (float) $supplierCost : null,
                'supplier_currency' => $supplierCurrency,
                'supplier_currency_id' => $currencyId,
                'tax_group' => $item->taxGroup ? [
                    'id' => $item->taxGroup->id,
                    'code' => $item->taxGroup->code,
                    'name' => $item->taxGroup->name,
                    'value' => (float) $item->taxGroup->value,
                ] : null,
                'purchase_uom' => $uomToUse ? [
                    'id' => $uomToUse->id,
                    'name' => $uomToUse->name,
                    'conversion' => $uomPivot?->pivot?->conversion ? (float) $uomPivot->pivot->conversion : 1,
                    'operation' => $uomPivot?->pivot?->operation ?? 'multiply',
                    'price_1' => $uomPivot?->pivot?->price_1 ? (float) $uomPivot->pivot->price_1 : 0,
                    'net_weight' => $uomPivot?->pivot?->net_weight ? (float) $uomPivot->pivot->net_weight : 0,
                    'net_volume' => $uomPivot?->pivot?->net_volume ? (float) $uomPivot->pivot->net_volume : 0,
                    'barcodes' => $barcodes,
                ] : null,
            ];
        })->filter()->values(); // Remove nulls and reindex

        return SupplierItemResource::collection($mapped)->resolve();
    }
}
